<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification;

use YouWillLikeIT\EGov\Enum\SupplementReason;
use YouWillLikeIT\EGov\Enum\VerificationLight;

/**
 * Structured outcome of one attachment verification (stored as JSON on the attachment).
 */
final readonly class VerificationResult
{
    /**
     * @param list<CheckResult> $checks
     */
    public function __construct(
        public VerificationLight $light,
        public string $summary,
        public array $checks = [],
        public string $detectedType = '',
        public ?bool $typeMatches = null,
        public float $confidence = 0.0,
        public ?SupplementReason $suggestedReason = null,
        public ?PaymentSlipData $paymentSlip = null,
        public string $aiModel = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'light' => $this->light->value,
            'summary' => $this->summary,
            'detected_type' => $this->detectedType,
            'type_matches' => $this->typeMatches,
            'confidence' => $this->confidence,
            'suggested_reason' => $this->suggestedReason?->value,
            'checks' => array_map(static fn (CheckResult $c): array => $c->toArray(), $this->checks),
            'payment_slip' => $this->paymentSlip?->toArray(),
            'ai_model' => $this->aiModel,
        ];
    }
}
