<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Enum;

/**
 * Closed list of formal reasons for dismissal (odbacivanje). The citizen email
 * is built from a CMS template per reason – no free text reaches the citizen.
 */
enum DismissalReason: string
{
    /** Nedostaci nisu otklonjeni u roku – smatra se da zahtjev nije podnesen. */
    case SupplementNotProvided = 'SupplementNotProvided';
    /** Organ nije nadležan. */
    case NotCompetent = 'NotCompetent';
    /** Podnosilac je povukao zahtjev. */
    case Withdrawn = 'Withdrawn';
    /** Zahtjev podnesen van propisanog roka. */
    case Untimely = 'Untimely';

    public function label(): string
    {
        return match ($this) {
            self::SupplementNotProvided => 'Nedostaci nisu otklonjeni u roku',
            self::NotCompetent => 'Nenadležnost',
            self::Withdrawn => 'Zahtjev povučen',
            self::Untimely => 'Neblagovremen zahtjev',
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
