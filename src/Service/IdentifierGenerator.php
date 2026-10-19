<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Service;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * Cryptographically random identifiers for the public portal.
 *
 *  - PublicHash:   64 hex chars, capability URL (upload / dopuna / pregled)
 *  - TrackingCode: PREFIX-XXXXXXXX (31-char alphabet without 0/O/1/I/L), shown to
 *                  the citizen and used together with JMBG for status lookup
 *  - Token:        64 hex chars, double-opt-in confirmation
 */
class IdentifierGenerator
{
    use Injectable;

    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function publicHash(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function trackingCode(): string
    {
        $prefix = TenantConfig::singleton()->trackingPrefix();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $prefix . '-' . $this->randomString(8);
            if (!ApplicationRequest::get()->filter('TrackingCode', $code)->exists()) {
                return $code;
            }
        }
        // 31^8 ≈ 8.5e11 combinations – reaching this point means something is badly wrong.
        throw new \RuntimeException('Nije moguće generisati jedinstven kod predmeta.');
    }

    public static function normalizeTrackingCode(string $input): string
    {
        // alphabet has no 0/1/O/I/L, so no look-alike mapping is needed
        return (string) preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($input)));
    }

    private function randomString(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
