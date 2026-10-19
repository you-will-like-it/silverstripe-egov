<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;
use Throwable;
use YouWillLikeIT\EGov\Admin\ApplicationRequestAdmin;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\NotificationTemplate;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * Email notifications. Texts come from CMS-editable NotificationTemplate records
 * (Intro / LegalNotice / Outro); the structured block (document list, payment data,
 * buttons) is generated. Mail failures never break the workflow.
 */
class NotificationService
{
    use Injectable;
    use Configurable;

    /**
     * @config
     * @var list<string>
     */
    private static array $notify_citizen_on = ['Incomplete', 'AwaitingPayment', 'InProcess', 'ReadyForPickup', 'Completed', 'Rejected', 'Dismissed'];

    /** @config */
    private static string $email_template = 'YouWillLikeIT/EGov/Email/Notification';

    public function shouldNotifyCitizen(RequestStatus $to): bool
    {
        return in_array($to->value, (array) static::config()->get('notify_citizen_on'), true);
    }

    public function sendConfirmationRequest(ApplicationRequest $request): bool
    {
        return $this->sendTemplate('confirm_submission', (string) $request->ApplicantEmail, $request, [
            'ButtonLink' => $request->getAbsoluteConfirmationLink(),
            'ButtonLabel' => 'Potvrđujem i podnosim zahtjev',
            'SmallPrint' => 'Link važi do ' . $request->formatDateTime((string) $request->ConfirmationExpiresAt) . '.',
        ]);
    }

    public function sendSubmissionReceipt(ApplicationRequest $request): bool
    {
        return $this->sendTemplate('submission_receipt', (string) $request->ApplicantEmail, $request, [
            'ShowCaseTable' => true,
            'Payments' => $request->getExpectedPayments(),
            'ButtonLink' => $request->getAbsolutePortalLink('predmet'),
            'ButtonLabel' => 'Otvori predmet',
        ]);
    }

    public function sendCounterReceipt(ApplicationRequest $request): bool
    {
        return $this->sendTemplate('counter_receipt', (string) $request->ApplicantEmail, $request, [
            'ShowCaseTable' => true,
        ]);
    }

    public function sendStatusChanged(ApplicationRequest $request, RequestStatus $to): bool
    {
        $extra = ['ShowCaseTable' => true];
        if ($to->isSupplementRound()) {
            $extra['NoticeText'] = (string) $request->SupplementRequestText;
            $extra['ButtonLink'] = $request->getAbsolutePortalLink('predmet');
            $extra['ButtonLabel'] = 'Dostavi dopunu';
        }
        if ($to === RequestStatus::AwaitingPayment) {
            $extra['Payments'] = $request->getExpectedPayments();
        }
        return $this->sendTemplate(NotificationTemplate::statusKey($to), (string) $request->ApplicantEmail, $request, $extra);
    }

    public function notifyServiceNewRequest(ApplicationRequest $request, bool $isSupplement = false): bool
    {
        return $this->sendTemplate(
            $isSupplement ? 'service_supplement' : 'service_new_request',
            (string) $request->Service()->Email,
            $request,
            ['ShowCaseTable' => true, 'ButtonLink' => $this->cmsLink($request), 'ButtonLabel' => 'Otvori predmet u CMS-u']
        );
    }

    /**
     * @param list<ApplicationRequest> $dueSoon
     * @param list<ApplicationRequest> $overdue
     * @param list<ApplicationRequest> $supplementExpired
     */
    public function sendDeadlineDigest(MunicipalService $service, array $dueSoon, array $overdue, array $supplementExpired = []): bool
    {
        if ($dueSoon === [] && $overdue === [] && $supplementExpired === []) {
            return false;
        }
        return $this->sendTemplate('deadline_digest', (string) $service->Email, null, [
            'DueSoon' => ArrayList::create($dueSoon),
            'Overdue' => ArrayList::create($overdue),
            'SupplementExpired' => ArrayList::create($supplementExpired),
        ], ['{Sluzba}' => (string) $service->Title]);
    }

    public function sendLoginCode(Member $member, string $code, int $minutes): bool
    {
        return $this->sendTemplate('login_code', (string) $member->Email, null, [], [
            '{Kod}' => $code,
            '{Minuta}' => (string) $minutes,
            '{Ime}' => (string) $member->getName(),
        ]);
    }

    /**
     * @param array<string, mixed> $blocks structured, non-editable template data
     * @param array<string, string> $vars extra placeholder values
     */
    protected function sendTemplate(string $key, string $to, ?ApplicationRequest $request, array $blocks = [], array $vars = []): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $template = NotificationTemplate::forKey($key);
        $vars = NotificationTemplate::variablesFor($request, $vars);
        $subject = $template->renderText('Subject', $vars) ?: TenantConfig::singleton()->fullName();

        return $this->send($to, $subject, array_merge([
            'Request' => $request,
            'IntroHtml' => $this->html($template->renderText('Intro', $vars)),
            'LegalHtml' => $this->html($template->renderText('LegalNotice', $vars)),
            'OutroHtml' => $this->html($template->renderText('Outro', $vars)),
        ], $blocks));
    }

    /**
     * Escaped text with line breaks – placeholders may contain citizen input.
     */
    private function html(string $text): ?\SilverStripe\ORM\FieldType\DBHTMLText
    {
        if ($text === '') {
            return null;
        }
        return DBField::create_field('HTMLFragment', nl2br(htmlspecialchars($text, ENT_QUOTES)));
    }

    private function cmsLink(ApplicationRequest $request): string
    {
        try {
            return (string) Director::absoluteURL(ApplicationRequestAdmin::singleton()->getCMSEditLinkForManagedDataObject($request));
        } catch (Throwable) {
            return (string) Director::absoluteURL('admin/e-zahtjevi');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function send(string $to, string $subject, array $data): bool
    {
        $tenant = TenantConfig::singleton();
        try {
            $email = Email::create()
                ->setTo($to)
                ->setSubject($subject)
                ->setHTMLTemplate((string) static::config()->get('email_template'))
                ->setData(array_merge($tenant->toTemplateData(), $data));
            $from = $tenant->fromEmail();
            if ($from !== '') {
                $email->setFrom($from, $tenant->fullName());
            }
            if ($tenant->email() !== '') {
                $email->setReplyTo($tenant->email());
            }
            $email->send();
            return true;
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error('[EGov] Email nije poslan', ['subject' => $subject, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
