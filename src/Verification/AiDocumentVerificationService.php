<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification;

use JsonException;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Services\AIGatewayService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Throwable;
use YouWillLikeIT\EGov\Enum\SupplementReason;
use YouWillLikeIT\EGov\Enum\VerificationLight;
use YouWillLikeIT\EGov\Model\ApplicationAttachment;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\FeeItem;
use YouWillLikeIT\EGov\Model\PaymentAccount;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Tenant\TenantConfig;
use YouWillLikeIT\EGov\Verification\TextExtraction\ChainTextExtractor;

/**
 * Verifies uploaded attachments and produces a traffic-light report.
 *
 * Pipeline per attachment:
 *  1. text extraction (PDF text layer → OCR fallback), local, no external calls
 *  2. deterministic payment slip rules (account / vrsta prihoda / općina / iznos)
 *     against the procedure's FeeItems and the tenant's municipality code
 *  3. AI (AIGateway, text category) – document type classification, independent
 *     extraction of slip fields (fills OCR gaps), proposed supplement text.
 *     The prompt is redacted (JMBG/ID, emails) and never contains the expected
 *     payment values, so an AI "match" cannot be an echo of our own input.
 *  4. combination into 🟢 / 🟡 / 🔴
 *
 * AIGateway is text-only today; when it gains multimodal input, step 1 can be
 * replaced by sending the image directly (swap ChainTextExtractor in Injector).
 */
class AiDocumentVerificationService
{
    use Injectable;
    use Configurable;

    /** @config */
    private static bool $ai_enabled = true;

    /** @config */
    private static bool $redact_personal_ids = true;

    /** @config */
    private static int $max_prompt_chars = 12000;

    /** @config */
    private static float $type_match_min_confidence = 0.8;

    /** @config */
    private static float $type_mismatch_min_confidence = 0.7;

    /** @config */
    private static int $ai_max_tokens = 1200;

    public function __construct(
        private ?AIGatewayService $gateway = null,
        private ?ChainTextExtractor $extractor = null,
        private ?PaymentSlipParser $slipParser = null,
        private ?SupplementNoticeBuilder $noticeBuilder = null,
    ) {
        $this->extractor ??= ChainTextExtractor::singleton();
        $this->slipParser ??= PaymentSlipParser::singleton();
        $this->noticeBuilder ??= SupplementNoticeBuilder::singleton();
    }

    /**
     * Verify every evidence attachment of the request (only Pending ones unless $force),
     * then update the request aggregate and the suggested supplement checklist.
     */
    public function verifyRequest(ApplicationRequest $request, bool $force = false): VerificationLight
    {
        $attachments = $request->evidenceAttachments();
        if (!$force) {
            $attachments = $attachments->filter('VerificationLight', VerificationLight::Pending->value);
        }
        foreach ($attachments as $attachment) {
            $this->verifyAttachment($attachment);
        }

        $request->refreshAiAggregate();
        $request->AiSupplementSuggestion = json_encode($this->noticeBuilder->suggest($request), JSON_FORCE_OBJECT);
        $request->AiCheckedAt = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp());
        $request->write();

        return $request->getAiLight();
    }

    public function verifyAttachment(ApplicationAttachment $attachment): VerificationResult
    {
        try {
            $result = $this->evaluate($attachment);
        } catch (Throwable $e) {
            $this->logger()->error('[EGov] Verifikacija priloga nije uspjela', [
                'attachment' => $attachment->ID,
                'error' => $e->getMessage(),
            ]);
            $result = new VerificationResult(
                light: VerificationLight::Error,
                summary: 'Automatska provjera nije uspjela – potrebna je ručna provjera.',
            );
        }

        $attachment->VerificationLight = $result->light->value;
        $attachment->VerificationReport = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $attachment->SuggestedReason = $result->suggestedReason?->value;
        $attachment->VerifiedAt = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp());
        $attachment->write();

        return $result;
    }

    private function evaluate(ApplicationAttachment $attachment): VerificationResult
    {
        $request = $attachment->Request();
        $document = $attachment->RequiredDocument();
        $file = $attachment->File();
        if (!$file->exists()) {
            return new VerificationResult(VerificationLight::Red, 'Datoteka priloga ne postoji.', suggestedReason: SupplementReason::Illegible);
        }

        $text = $this->extractor->extractFromFile($file);
        $checks = [];
        $aiOn = $this->aiEnabledFor($request);

        // --- AI pass (classification + independent slip extraction) – optional per procedure
        $ai = $aiOn ? $this->askAi($request, $document, $text) : $this->emptyAi();

        // --- Document type
        $typeMatches = $ai['matches_expected'];
        $confidence = $ai['confidence'];
        if (!$aiOn) {
            if (!$document->IsPaymentProof) {
                $checks[] = new CheckResult('type', 'Vrsta dokumenta', VerificationLight::Yellow, $text === ''
                    ? 'Tekst dokumenta nije čitljiv (sken/fotografija loše kvalitete) – ručna provjera.'
                    : 'Automatsko prepoznavanje vrste dokumenta je isključeno za ovu proceduru – ručna provjera.', 'pravila');
            }
        } elseif ($typeMatches === true && $confidence >= (float) static::config()->get('type_match_min_confidence')) {
            $checks[] = new CheckResult('type', 'Vrsta dokumenta', VerificationLight::Green, sprintf(
                'Prepoznat dokument: %s (pouzdanost %d%%).',
                $ai['detected_type'] ?: $document->Title,
                (int) round($confidence * 100)
            ), 'AI');
        } elseif ($typeMatches === false && $confidence >= (float) static::config()->get('type_mismatch_min_confidence')) {
            $checks[] = new CheckResult('type', 'Vrsta dokumenta', VerificationLight::Red, sprintf(
                'Priloženi dokument izgleda kao „%s“, a očekuje se „%s“.',
                $ai['detected_type'] ?: 'nepoznat dokument',
                $document->Title
            ), 'AI');
        } else {
            $message = $text === ''
                ? 'Tekst dokumenta nije bilo moguće pročitati (sken/fotografija loše kvalitete).'
                : 'Vrsta dokumenta nije pouzdano prepoznata.';
            if ($ai['error'] !== null && $text !== '') {
                $message .= ' ' . $ai['error'];
            }
            $checks[] = new CheckResult('type', 'Vrsta dokumenta', VerificationLight::Yellow, $message, 'AI');
        }

        // --- Payment slip rules (always on, deterministic)
        $slip = null;
        if ($document->IsPaymentProof) {
            $slip = $this->slipParser->parse($text);
            if ($ai['payment_slip'] instanceof PaymentSlipData) {
                $slip = $slip->mergeMissingFrom($ai['payment_slip']);
            }
            array_push($checks, ...$this->paymentChecks($request, $slip));
        }

        $light = $this->combine($checks);

        return new VerificationResult(
            light: $light,
            summary: $this->summary($light, $checks),
            checks: $checks,
            detectedType: (string) $ai['detected_type'],
            typeMatches: $typeMatches,
            confidence: $confidence,
            suggestedReason: $this->suggestReason($light, $checks, $text),
            paymentSlip: $slip,
            aiModel: (string) $ai['model'],
        );
    }

    private function aiEnabledFor(ApplicationRequest $request): bool
    {
        return (bool) static::config()->get('ai_enabled') && (bool) $request->Procedure()->AiCheckEnabled;
    }

    /**
     * @param list<CheckResult> $checks
     */
    private function suggestReason(VerificationLight $light, array $checks, string $text): ?SupplementReason
    {
        if ($light === VerificationLight::Green) {
            return null;
        }
        foreach ($checks as $check) {
            if ($check->light === VerificationLight::Red && in_array($check->key, ['account', 'revenue', 'municipality', 'budget', 'amount', 'fee'], true)) {
                return SupplementReason::WrongPayment;
            }
        }
        foreach ($checks as $check) {
            if ($check->light === VerificationLight::Red && $check->key === 'type') {
                return SupplementReason::WrongDocument;
            }
        }
        return trim($text) === '' ? SupplementReason::Illegible : null;
    }

    /**
     * @return array{error: ?string, matches_expected: ?bool, confidence: float, detected_type: string,
     *               payment_slip: ?PaymentSlipData, supplement_text: string, model: string}
     */
    private function emptyAi(): array
    {
        return [
            'error' => null,
            'matches_expected' => null,
            'confidence' => 0.0,
            'detected_type' => '',
            'payment_slip' => null,
            'supplement_text' => '',
            'model' => '',
        ];
    }

    /**
     * @return list<CheckResult>
     */
    public function paymentChecks(ApplicationRequest $request, PaymentSlipData $slip): array
    {
        $checks = [];
        $fees = $request->getApplicableFees()->filter('PaymentAccountID:GreaterThan', 0);
        $tenantCode = TenantConfig::singleton()->municipalityCode();
        $src = (string) $slip->source;

        if ($fees->count() === 0) {
            return [new CheckResult('fee', 'Taksa', VerificationLight::Yellow, 'Za ovu proceduru nije definisan račun za uplatu – provjerite ručno.')];
        }

        // pick the fee the slip most likely pays
        $best = null;
        $bestScore = -1;
        foreach ($fees as $fee) {
            $score = 0;
            if (in_array((string) $fee->PaymentAccount()->AccountNumber, $slip->accounts, true)) {
                $score += 2;
            }
            if ($fee->RevenueType && $fee->RevenueType === $slip->revenueType) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $best = $fee;
                $bestScore = $score;
            }
        }
        /** @var FeeItem $best */
        $expectedAccount = (string) $best->PaymentAccount()->AccountNumber;

        // 1. Račun primaoca
        if (in_array($expectedAccount, $slip->accounts, true)) {
            $checks[] = new CheckResult('account', 'Račun primaoca', VerificationLight::Green, sprintf(
                'Uplata na ispravan račun %s.',
                PaymentAccount::format($expectedAccount)
            ), $src);
        } elseif ($slip->accounts === []) {
            $checks[] = new CheckResult('account', 'Račun primaoca', VerificationLight::Yellow, 'Račun primaoca nije čitljiv na uplatnici.', $src);
        } else {
            $known = array_filter($slip->accounts, static fn (string $a): bool => TenantConfig::singleton()->isKnownAccount($a));
            $checks[] = new CheckResult('account', 'Račun primaoca', $known !== [] || count($slip->accounts) === 1 ? VerificationLight::Red : VerificationLight::Yellow, sprintf(
                'Na uplatnici je račun %s, a za „%s“ se uplaćuje na %s.',
                PaymentAccount::format((string) ($known !== [] ? reset($known) : $slip->accounts[0])),
                $best->Title,
                PaymentAccount::format($expectedAccount)
            ), $src);
        }

        // 2. Vrsta prihoda
        if ($best->RevenueType) {
            $checks[] = match (true) {
                $slip->revenueType === null => new CheckResult('revenue', 'Vrsta prihoda', VerificationLight::Yellow, 'Vrsta prihoda nije čitljiva.', $src),
                $slip->revenueType === $best->RevenueType => new CheckResult('revenue', 'Vrsta prihoda', VerificationLight::Green, sprintf('Vrsta prihoda %s je ispravna.', $best->RevenueType), $src),
                default => new CheckResult('revenue', 'Vrsta prihoda', VerificationLight::Red, sprintf(
                    'Navedena vrsta prihoda %s, a propisana je %s.',
                    $slip->revenueType,
                    $best->RevenueType
                ), $src),
            };
        }

        // 3. Općina
        if ($tenantCode !== '') {
            $checks[] = match (true) {
                $slip->municipalityCode === null => new CheckResult('municipality', 'Šifra općine', VerificationLight::Yellow, 'Šifra općine nije čitljiva.', $src),
                $slip->municipalityCode === $tenantCode => new CheckResult('municipality', 'Šifra općine', VerificationLight::Green, sprintf('Šifra općine %s je ispravna.', $tenantCode), $src),
                default => new CheckResult('municipality', 'Šifra općine', VerificationLight::Red, sprintf(
                    'Na uplatnici je šifra općine %s, a treba biti %s.',
                    $slip->municipalityCode,
                    $tenantCode
                ), $src),
            };
        }

        // 4. Budžetska organizacija (only when configured)
        if ($best->BudgetOrganization && $slip->budgetOrganization !== null && $slip->budgetOrganization !== $best->BudgetOrganization) {
            $checks[] = new CheckResult('budget', 'Budžetska organizacija', VerificationLight::Red, sprintf(
                'Budžetska organizacija %s ne odgovara propisanoj %s.',
                $slip->budgetOrganization,
                $best->BudgetOrganization
            ), $src);
        }

        // 5. Iznos
        [$min, $max] = $request->expectedRangeFor($best);
        if ($max <= 0.0) {
            $checks[] = new CheckResult('amount', 'Iznos', VerificationLight::Yellow, sprintf(
                'Iznos za „%s“ nije definisan u katalogu%s – referent provjerava prema tarifi.',
                $best->Title,
                $slip->amount !== null ? ' (uplaćeno ' . FeeItem::km($slip->amount) . ')' : ''
            ), $src);
        } elseif ($slip->amount === null) {
            $checks[] = new CheckResult('amount', 'Iznos', VerificationLight::Yellow, sprintf('Iznos nije čitljiv (očekuje se %s).', $this->rangeLabel($min, $max)), $src);
        } elseif ($slip->amount + 0.001 < $min) {
            $checks[] = new CheckResult('amount', 'Iznos', VerificationLight::Red, sprintf(
                'Uplaćeno %s, a propisano je %s.',
                FeeItem::km($slip->amount),
                $this->rangeLabel($min, $max)
            ), $src);
        } elseif ($slip->amount - 0.001 > $max) {
            $checks[] = new CheckResult('amount', 'Iznos', VerificationLight::Yellow, sprintf(
                'Uplaćeno %s, što je više od propisanog (%s).',
                FeeItem::km($slip->amount),
                $this->rangeLabel($min, $max)
            ), $src);
        } else {
            $checks[] = new CheckResult('amount', 'Iznos', $min === $max ? VerificationLight::Green : VerificationLight::Yellow, $min === $max
                ? sprintf('Iznos %s je ispravan.', FeeItem::km($slip->amount))
                : sprintf('Iznos %s je u propisanom rasponu %s – referent potvrđuje tačan iznos.', FeeItem::km($slip->amount), $this->rangeLabel($min, $max)), $src);
        }

        return $checks;
    }

    /**
     * @param list<CheckResult> $checks
     */
    private function combine(array $checks): VerificationLight
    {
        $light = VerificationLight::Green;
        foreach ($checks as $check) {
            if ($check->light->severity() > $light->severity()) {
                $light = $check->light;
            }
        }
        // An AI "Error" alone (rules fine) is not a red flag – downgrade to manual check.
        return $light === VerificationLight::Error ? VerificationLight::Yellow : $light;
    }

    /**
     * @param list<CheckResult> $checks
     */
    private function summary(VerificationLight $light, array $checks): string
    {
        $problems = array_filter($checks, static fn (CheckResult $c): bool => $c->light !== VerificationLight::Green);
        return match ($light) {
            VerificationLight::Green => 'Dokument je ispravan prema svim provjerama.',
            VerificationLight::Red => 'Utvrđene nepravilnosti: ' . implode(' ', array_map(static fn (CheckResult $c): string => $c->message, $problems)),
            default => 'Potrebna ručna provjera: ' . implode(' ', array_map(static fn (CheckResult $c): string => $c->message, $problems)),
        };
    }

    /**
     * @return array{error: ?string, matches_expected: ?bool, confidence: float, detected_type: string,
     *               payment_slip: ?PaymentSlipData, supplement_text: string, model: string}
     */
    private function askAi(ApplicationRequest $request, RequiredDocument $document, string $text): array
    {
        $empty = [
            'error' => null,
            'matches_expected' => null,
            'confidence' => 0.0,
            'detected_type' => '',
            'payment_slip' => null,
            'supplement_text' => '',
            'model' => '',
        ];
        if (!(bool) static::config()->get('ai_enabled')) {
            return ['error' => 'AI analiza je isključena u konfiguraciji.'] + $empty;
        }
        if (trim($text) === '') {
            return ['error' => 'Nema čitljivog teksta za AI analizu.'] + $empty;
        }
        $gateway = $this->gateway ?? Injector::inst()->get(AIGatewayService::class);

        try {
            $response = $gateway->ask($this->buildPrompt($request, $document, $text), [
                'system' => $this->systemPrompt(),
                'temperature' => 0.0,
                'max_tokens' => (int) static::config()->get('ai_max_tokens'),
                'caller_class' => self::class,
                'caller_context' => sprintf('egov-attachment:%s', $request->TrackingCode),
            ]);
        } catch (AIProviderException $e) {
            return ['error' => 'AI servis nije dostupan: ' . $e->getMessage()] + $empty;
        }

        $data = $this->decodeJson($response->content);
        if ($data === null) {
            return ['error' => 'AI odgovor nije u očekivanom formatu.', 'model' => $response->model] + $empty;
        }

        $slip = null;
        if (isset($data['payment_slip']) && is_array($data['payment_slip'])) {
            $p = $data['payment_slip'];
            $account = PaymentAccount::normalize((string) ($p['account'] ?? ''));
            $slip = new PaymentSlipData(
                accounts: strlen($account) === 16 ? [$account] : [],
                revenueType: $this->digitsOrNull($p['revenue_type'] ?? null, 6),
                municipalityCode: $this->digitsOrNull($p['municipality_code'] ?? null, 3),
                budgetOrganization: $this->digitsOrNull($p['budget_organization'] ?? null, 7),
                referenceNumber: $this->digitsOrNull($p['reference_number'] ?? null, 10),
                amount: is_numeric($p['amount'] ?? null) ? round((float) $p['amount'], 2) : null,
                source: 'AI',
            );
        }

        return [
            'error' => null,
            'matches_expected' => is_bool($data['matches_expected'] ?? null) ? $data['matches_expected'] : null,
            'confidence' => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            'detected_type' => mb_substr((string) ($data['detected_document_type'] ?? ''), 0, 200),
            'payment_slip' => $slip,
            'supplement_text' => mb_substr(trim((string) ($data['supplement_text'] ?? '')), 0, 1500),
            'model' => $response->model,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<TXT
You are a document intake assistant of a municipal administration in Bosnia and Herzegovina (Federation of BiH).
You receive OCR text of ONE document a citizen attached to an administrative request, and the document type that was expected.
Tasks:
1. Decide whether the text belongs to the expected document type.
2. If the text is a payment slip ("uplatnica", "nalog za uplatu javnih prihoda"), extract its fields exactly as written; never guess or complete numbers.
Respond with ONLY a JSON object, no markdown, using this schema:
{"detected_document_type": string (Bosnian), "matches_expected": boolean|null, "confidence": number 0..1, "readable": boolean,
 "payment_slip": null | {"account": string|null (16 digits), "revenue_type": string|null (6 digits), "municipality_code": string|null (3 digits),
   "budget_organization": string|null (7 digits), "reference_number": string|null, "amount": number|null, "purpose": string|null},
 "issues": [string]}
TXT;
    }

    private function buildPrompt(ApplicationRequest $request, RequiredDocument $document, string $text): string
    {
        $text = (bool) static::config()->get('redact_personal_ids') ? $this->redact($text) : $text;
        $text = mb_substr($text, 0, (int) static::config()->get('max_prompt_chars'));

        return sprintf(
            "Procedura: %s – %s\nOčekivani dokument: %s\nIzdavalac očekivanog dokumenta: %s\nOpis za prepoznavanje: %s\nOčekuje se uplatnica: %s\n\n--- TEKST DOKUMENTA (OCR) ---\n%s\n--- KRAJ ---",
            $request->RegistryCode,
            $request->Procedure()->Title,
            $document->Title,
            $document->Issuer ?: 'nije navedeno',
            $document->AiHint ?: '—',
            $document->IsPaymentProof ? 'da' : 'ne',
            $text
        );
    }

    /**
     * Masks JMBG/ID numbers (13 digits) and email addresses before text leaves the server.
     * Account numbers (16 digits) are kept – they are public data of the municipality.
     */
    public function redact(string $text): string
    {
        $text = (string) preg_replace('/(?<!\d)\d{13}(?!\d)/', '[JMBG/ID]', $text);
        return (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[EMAIL]', $text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);
        $content = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        try {
            $data = json_decode(substr($content, $start, $end - $start + 1), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    private function digitsOrNull(mixed $value, int $length): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $value);
        return strlen($digits) === $length ? $digits : null;
    }

    private function rangeLabel(float $min, float $max): string
    {
        return $min === $max ? FeeItem::km($min) : FeeItem::km($min) . ' – ' . FeeItem::km($max);
    }

    private function logger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }
}
