<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification;

use YouWillLikeIT\EGov\Enum\VerificationLight;

final readonly class CheckResult
{
    public function __construct(
        public string $key,
        public string $label,
        public VerificationLight $light,
        public string $message,
        public string $source = 'pravila',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'light' => $this->light->value,
            'message' => $this->message,
            'source' => $this->source,
        ];
    }
}
