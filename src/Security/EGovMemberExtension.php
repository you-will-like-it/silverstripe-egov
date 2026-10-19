<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Security;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Service\AuditLogger;

/**
 * Email one-time-code state + login audit hooks.
 *
 * @property string|null $EGovOtpHash
 * @property string|null $EGovOtpExpiresAt
 * @property string|null $EGovOtpSentAt
 * @property int $EGovOtpAttempts
 * @extends Extension<Member>
 */
class EGovMemberExtension extends Extension
{
    private static array $db = [
        'EGovOtpHash' => 'Varchar(255)',
        'EGovOtpExpiresAt' => 'Datetime',
        'EGovOtpSentAt' => 'Datetime',
        'EGovOtpAttempts' => 'Int',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['EGovOtpHash', 'EGovOtpExpiresAt', 'EGovOtpSentAt', 'EGovOtpAttempts']);
    }

    protected function onAfterMemberLoggedIn(): void
    {
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_LOGIN, null, '', $this->getOwner());
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function onAuthenticationFailed(array $data, ?HTTPRequest $request = null): void
    {
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_LOGIN_FAILED, null, 'Pogrešna lozinka', $this->getOwner());
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function onAuthenticationFailedUnknownUser(array $data, ?HTTPRequest $request = null): void
    {
        $email = (string) ($data['Email'] ?? '');
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_LOGIN_FAILED, null, 'Nepoznat korisnik: ' . mb_substr($email, 0, 120));
    }
}
