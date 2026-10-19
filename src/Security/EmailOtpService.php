<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Security;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Service\AuditLogger;
use YouWillLikeIT\EGov\Service\NotificationService;

/**
 * Second login step: a personal one-time code sent to the referent's own email.
 * (Instead of a shared daily password: every login stays attributable to one person.)
 */
class EmailOtpService
{
    use Injectable;
    use Configurable;

    public const SESSION_KEY = 'EGovOtpVerifiedMember';

    /** @config */
    private static bool $enabled = true;

    /** @config */
    private static int $code_ttl_minutes = 10;

    /** @config */
    private static int $resend_cooldown_seconds = 60;

    /** @config */
    private static int $max_attempts = 5;

    /**
     * URL prefixes that require the second factor.
     *
     * @config
     * @var list<string>
     */
    private static array $protected_prefixes = ['admin', 'egov-datoteke', 'egov-izvjestaji'];

    /**
     * Members with this permission skip the second step (e.g. a technical account). Empty = nobody.
     *
     * @config
     */
    private static string $exempt_permission = '';

    public function isEnabled(): bool
    {
        return (bool) static::config()->get('enabled');
    }

    public function isVerified(HTTPRequest $request, Member $member): bool
    {
        return $request->hasSession() && (int) $request->getSession()->get(self::SESSION_KEY) === (int) $member->ID;
    }

    public function markVerified(HTTPRequest $request, Member $member): void
    {
        $request->getSession()->set(self::SESSION_KEY, (int) $member->ID);
    }

    public function isProtectedPath(string $url): bool
    {
        $url = ltrim($url, '/');
        foreach ((array) static::config()->get('protected_prefixes') as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && ($url === $prefix || str_starts_with($url, $prefix . '/'))) {
                return true;
            }
        }
        return false;
    }

    public function canResend(Member $member): bool
    {
        $sent = strtotime((string) $member->EGovOtpSentAt);
        return $sent === false || ($this->now() - $sent) >= (int) static::config()->get('resend_cooldown_seconds');
    }

    public function send(Member $member): bool
    {
        if (!$this->canResend($member)) {
            return false;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = max(1, (int) static::config()->get('code_ttl_minutes'));
        $member->EGovOtpHash = password_hash($code, PASSWORD_DEFAULT);
        $member->EGovOtpExpiresAt = date('Y-m-d H:i:s', $this->now() + $ttl * 60);
        $member->EGovOtpSentAt = date('Y-m-d H:i:s', $this->now());
        $member->EGovOtpAttempts = 0;
        $member->write();

        AuditLogger::singleton()->log(AuditLogEntry::ACTION_OTP_SENT, null, '', $member);
        return NotificationService::singleton()->sendLoginCode($member, $code, $ttl);
    }

    public function verify(Member $member, string $code): bool
    {
        $code = (string) preg_replace('/\D+/', '', $code);
        $expires = strtotime((string) $member->EGovOtpExpiresAt);
        $attempts = (int) $member->EGovOtpAttempts;

        $valid = $member->EGovOtpHash
            && $expires !== false && $expires >= $this->now()
            && $attempts < (int) static::config()->get('max_attempts')
            && strlen($code) === 6
            && password_verify($code, (string) $member->EGovOtpHash);

        if ($valid) {
            $member->EGovOtpHash = null;
            $member->EGovOtpAttempts = 0;
            $member->write();
            AuditLogger::singleton()->log(AuditLogEntry::ACTION_OTP_OK, null, '', $member);
            return true;
        }

        $member->EGovOtpAttempts = $attempts + 1;
        $member->write();
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_OTP_FAILED, null, 'Pokušaj ' . ($attempts + 1), $member);
        return false;
    }

    public function attemptsExhausted(Member $member): bool
    {
        return (int) $member->EGovOtpAttempts >= (int) static::config()->get('max_attempts');
    }

    public function isExempt(Member $member): bool
    {
        $perm = (string) static::config()->get('exempt_permission');
        return $perm !== '' && \SilverStripe\Security\Permission::checkMember($member, $perm);
    }

    private function now(): int
    {
        return DBDatetime::now()->getTimestamp();
    }
}
