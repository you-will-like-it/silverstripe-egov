<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification;

/**
 * Values read from a BiH "uplatnica za javne prihode".
 * Null = not found / not readable.
 */
final readonly class PaymentSlipData
{
    /**
     * @param list<string> $accounts all 16-digit account candidates found
     */
    public function __construct(
        public array $accounts = [],
        public ?string $revenueType = null,
        public ?string $municipalityCode = null,
        public ?string $budgetOrganization = null,
        public ?string $referenceNumber = null,
        public ?float $amount = null,
        public ?string $source = 'pravila',
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->accounts === []
            && $this->revenueType === null
            && $this->municipalityCode === null
            && $this->amount === null;
    }

    /**
     * Fill gaps of this (rule based) result with values from another source (AI).
     */
    public function mergeMissingFrom(self $other): self
    {
        return new self(
            accounts: $this->accounts !== [] ? $this->accounts : $other->accounts,
            revenueType: $this->revenueType ?? $other->revenueType,
            municipalityCode: $this->municipalityCode ?? $other->municipalityCode,
            budgetOrganization: $this->budgetOrganization ?? $other->budgetOrganization,
            referenceNumber: $this->referenceNumber ?? $other->referenceNumber,
            amount: $this->amount ?? $other->amount,
            source: $this->isEmpty() ? $other->source : $this->source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accounts' => $this->accounts,
            'revenue_type' => $this->revenueType,
            'municipality_code' => $this->municipalityCode,
            'budget_organization' => $this->budgetOrganization,
            'reference_number' => $this->referenceNumber,
            'amount' => $this->amount,
            'source' => $this->source,
        ];
    }
}
