<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\EGov\Enum\SupplementReason;
use YouWillLikeIT\EGov\Enum\VerificationLight;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\FeeItem;
use YouWillLikeIT\EGov\Model\NotificationTemplate;
use YouWillLikeIT\EGov\Model\PaymentAccount;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Tenant\TenantConfig;
use YouWillLikeIT\EGov\Workflow\TransitionException;

/**
 * Closed-list supplement requests ("anti-šikaniranje"):
 *  - only documents from the procedure catalogue,
 *  - only reasons from SupplementReason, constrained by whether the document was attached,
 *  - the citizen text is assembled from the CMS template "supplement_notice".
 */
class SupplementNoticeBuilder
{
    use Injectable;

    /**
     * Suggested checklist from rule/AI verification (pre-selection in CMS, never sent automatically).
     *
     * @return array<int, string> RequiredDocumentID => SupplementReason value
     */
    public function suggest(ApplicationRequest $request): array
    {
        $out = [];
        foreach ($request->getMissingMandatoryDocuments() as $doc) {
            $out[(int) $doc->ID] = SupplementReason::Missing->value;
        }
        foreach ($request->evidenceAttachments()->filter('VerificationLight', VerificationLight::Red->value) as $attachment) {
            $reason = SupplementReason::tryFrom((string) $attachment->SuggestedReason);
            if ($reason !== null && $attachment->RequiredDocumentID) {
                $out[(int) $attachment->RequiredDocumentID] = $reason->value;
            }
        }
        return $out;
    }

    /**
     * @param array<int, string> $items RequiredDocumentID => SupplementReason value
     * @return array<int, SupplementReason> validated items
     */
    public function validateItems(ApplicationRequest $request, array $items): array
    {
        if ($items === []) {
            throw new TransitionException('Odaberite barem jedan dokument koji stranka treba dopuniti.');
        }
        $docs = [];
        foreach ($request->Procedure()->citizenDocuments() as $doc) {
            $docs[(int) $doc->ID] = $doc;
        }
        $uploaded = array_map('intval', $request->evidenceAttachments()->sort(null)->columnUnique('RequiredDocumentID'));

        $valid = [];
        foreach ($items as $docId => $reasonValue) {
            $doc = $docs[(int) $docId] ?? null;
            $reason = SupplementReason::tryFrom((string) $reasonValue);
            if (!$doc instanceof RequiredDocument || $reason === null) {
                throw new TransitionException('Dopuna se može tražiti samo za dokumente iz kataloga procedure.');
            }
            $allowed = SupplementReason::optionsFor(in_array((int) $docId, $uploaded, true), (bool) $doc->IsPaymentProof);
            if (!array_key_exists($reason->value, $allowed)) {
                throw new TransitionException(sprintf('Razlog „%s“ nije dozvoljen za dokument „%s“.', $reason->label(), $doc->Title));
            }
            $valid[(int) $docId] = $reason;
        }
        return $valid;
    }

    /**
     * Text stored on the request (SupplementRequestText), shown on the portal and in the email.
     *
     * @param array<int, SupplementReason> $items
     */
    public function render(ApplicationRequest $request, array $items, int $days): string
    {
        $template = NotificationTemplate::forKey('supplement_notice');
        $vars = NotificationTemplate::variablesFor($request, ['{BrojDanaDopuna}' => (string) $days]);

        $lines = [];
        $intro = $template->renderText('Intro', $vars);
        if ($intro !== '') {
            $lines[] = $intro;
        }
        $i = 1;
        foreach ($items as $docId => $reason) {
            $doc = RequiredDocument::get()->byID($docId);
            if (!$doc) {
                continue;
            }
            $line = sprintf('%d. %s – %s', $i++, $doc->Title, mb_strtolower($reason->label()));
            if ($reason === SupplementReason::WrongPayment || ($doc->IsPaymentProof && $reason === SupplementReason::Missing)) {
                $line .= ' (' . $this->paymentLine($request) . ')';
            }
            $lines[] = $line . '.';
        }
        foreach (['LegalNotice', 'Outro'] as $part) {
            $text = $template->renderText($part, $vars);
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        return implode("\n", $lines);
    }

    private function paymentLine(ApplicationRequest $request): string
    {
        $parts = [];
        foreach ($request->getApplicableFees() as $fee) {
            /** @var FeeItem $fee */
            [$min, $max] = $fee->expectedRange($request->getFeeUnitCount());
            $parts[] = sprintf(
                '%s: račun %s, vrsta prihoda %s, šifra općine %s%s, iznos %s',
                $fee->Title,
                PaymentAccount::format((string) $fee->PaymentAccount()->AccountNumber),
                $fee->RevenueType ?: '—',
                TenantConfig::singleton()->municipalityCode() ?: '—',
                $fee->BudgetOrganization ? ', budžetska organizacija ' . $fee->BudgetOrganization : '',
                $max <= 0.0 ? ($fee->AmountNote ?: 'prema tarifi') : ($min === $max ? FeeItem::km($min) : FeeItem::km($min) . ' – ' . FeeItem::km($max))
            );
        }
        return implode('; ', $parts) ?: 'prema propisanim podacima za uplatu';
    }
}
