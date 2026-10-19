<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Validation;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\EGov\Enum\ApplicantType;

/**
 * Validates JMBG (natural persons) and ID/JIB (legal entities, 13 digits starting with 4).
 *
 * JMBG control digit (mod 11, weights 7..2,7..2):
 *   m = 11 - (sum mod 11); m == 11 → 0; m == 10 → invalid; otherwise m.
 * For legal entities only format is enforced (the same mod-11 scheme is checked
 * when strict_company_checksum is enabled, because issuers historically differ).
 */
class IdentificationNumberValidator
{
    use Injectable;

    private const WEIGHTS = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public function __construct(private readonly bool $strictCompanyChecksum = false)
    {
    }

    /**
     * @return string|null Error message in Bosnian, or null when valid.
     */
    public function validate(string $value, ApplicantType $type): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        if (strlen($digits) !== 13) {
            return $type === ApplicantType::Individual
                ? 'JMBG mora imati tačno 13 cifara.'
                : 'Identifikacioni broj mora imati tačno 13 cifara.';
        }

        if ($type === ApplicantType::Individual) {
            if (!$this->isValidJmbgDate($digits)) {
                return 'JMBG sadrži neispravan datum rođenja.';
            }
            return $this->checksumValid($digits) ? null : 'JMBG nije ispravan (kontrolna cifra se ne poklapa).';
        }

        if ($digits[0] !== '4') {
            return 'Identifikacioni broj pravnog lica/obrta počinje cifrom 4.';
        }
        if ($this->strictCompanyChecksum && !$this->checksumValid($digits)) {
            return 'Identifikacioni broj nije ispravan (kontrolna cifra se ne poklapa).';
        }
        return null;
    }

    public function checksumValid(string $digits): bool
    {
        if (!preg_match('/^\d{13}$/', $digits)) {
            return false;
        }
        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }
        $m = 11 - ($sum % 11);
        if ($m === 10) {
            return false;
        }
        if ($m === 11) {
            $m = 0;
        }
        return $m === (int) $digits[12];
    }

    private function isValidJmbgDate(string $digits): bool
    {
        $day = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $yearPart = (int) substr($digits, 4, 3);
        // 000-099 → 2000-2099, 800-999 → 1800-1999
        $year = $yearPart < 800 ? 2000 + $yearPart : 1000 + $yearPart;
        return checkdate($month, $day, $year);
    }
}
