<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Enum;

/**
 * Statutory deadline categories according to Zakon o upravnom postupku FBiH.
 * Concrete day counts live in YAML ({@see \YouWillLikeIT\EGov\Service\DeadlineCalculator}).
 */
enum DeadlineCategory: string
{
    /** Bez posebnog ispitnog postupka (čl. 216. st. 1. ZUP FBiH). */
    case Simple = 'Simple';
    /** Sa posebnim ispitnim postupkom (čl. 216. st. 2. ZUP FBiH). */
    case Examination = 'Examination';
    /** Uvjerenja i potvrde o činjenicama iz službene evidencije. */
    case Certificate = 'Certificate';

    public function label(): string
    {
        return match ($this) {
            self::Simple => _t(self::class . '.Simple', 'Bez ispitnog postupka'),
            self::Examination => _t(self::class . '.Examination', 'Sa posebnim ispitnim postupkom'),
            self::Certificate => _t(self::class . '.Certificate', 'Uvjerenje / potvrda'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
