<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification;

use SilverStripe\Core\Injector\Injectable;

/**
 * Deterministic parser for OCR/text of a FBiH payment slip for public revenues.
 *
 * Slip fields: Račun primaoca (16), Broj poreznog obveznika (13), Vrsta uplate (1),
 * Vrsta prihoda (6), Porezni period, Općina (3), Budžetska organizacija (7),
 * Poziv na broj (10), Iznos.
 *
 * Legal compliance must not depend on an LLM – these values are compared by
 * rules; AI output only fills gaps when OCR could not read a field.
 */
class PaymentSlipParser
{
    use Injectable;

    public function parse(string $text): PaymentSlipData
    {
        $flat = $this->flatten($text);

        return new PaymentSlipData(
            accounts: $this->accounts($flat),
            revenueType: $this->labelled($flat, '(?:vrsta\s*prihoda)', 6) ?? $this->fallbackRevenueType($flat),
            municipalityCode: $this->labelled($flat, '(?:op[cćč]ina|opstina)', 3),
            budgetOrganization: $this->labelled($flat, '(?:bud[zž]etska\s*organizacija)', 7),
            referenceNumber: $this->labelled($flat, '(?:poziv\s*na\s*broj)', 10),
            amount: $this->amount($flat),
        );
    }

    /**
     * @return list<string>
     */
    private function accounts(string $text): array
    {
        $found = [];
        // 3-3-4-3-3 with optional separators, or a plain 16 digit run
        if (preg_match_all('/(?<!\d)(\d{3})[\s.\-]?(\d{3})[\s.\-]?(\d{4})[\s.\-]?(\d{3})[\s.\-]?(\d{3})(?!\d)/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = $match[1] . $match[2] . $match[3] . $match[4] . $match[5];
            }
        }
        return array_values(array_unique($found));
    }

    private function labelled(string $text, string $labelPattern, int $digits): ?string
    {
        // label, up to 25 non-digit chars (":", newlines, box borders), then exactly N digits
        $pattern = sprintf('/%s[^\d]{0,25}((?:\d[\s]?){%d})(?!\d)/iu', $labelPattern, $digits);
        if (preg_match($pattern, $text, $m)) {
            $value = (string) preg_replace('/\s+/', '', $m[1]);
            return strlen($value) === $digits ? $value : null;
        }
        return null;
    }

    /**
     * Revenue types of municipal fees are 72xxxx; use only when exactly one candidate exists.
     */
    private function fallbackRevenueType(string $text): ?string
    {
        if (preg_match_all('/(?<!\d)(72\d{4})(?!\d)/', $text, $m) && count(array_unique($m[1])) === 1) {
            return $m[1][0];
        }
        return null;
    }

    private function amount(string $text): ?float
    {
        $patterns = [
            '/iznos[^\d]{0,20}(\d{1,3}(?:[.\s]\d{3})*(?:,\d{2})|\d+(?:[.,]\d{2}))/iu',
            '/(\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+[.,]\d{2})\s*(?:KM|BAM)/iu',
            '/(?:KM|BAM)\s*(\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+[.,]\d{2})/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return self::toFloat($m[1]);
            }
        }
        return null;
    }

    public static function toFloat(string $raw): ?float
    {
        $raw = trim(str_replace(' ', '', $raw));
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, ',')) {
            // BiH format 1.234,56
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    private function flatten(string $text): string
    {
        // Common OCR confusions inside digit groups: O→0, l/I→1
        $text = (string) preg_replace_callback('/[\dOlI]{3,}/u', static function (array $m): string {
            return preg_match('/\d/', $m[0]) ? strtr($m[0], ['O' => '0', 'l' => '1', 'I' => '1']) : $m[0];
        }, $text);
        return (string) preg_replace('/[ \t]+/u', ' ', $text);
    }
}
