<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Append-only access trail (who saw / changed / downloaded which case).
 * Snapshots (member name, tracking code) instead of hard relations so the
 * trail survives deletion of the case or the member account.
 *
 * @property string $Action
 * @property int $MemberID
 * @property string $MemberName
 * @property int $RequestID
 * @property string $TrackingCode
 * @property string $Details
 * @property string $IP
 * @property string $UserAgent
 */
class AuditLogEntry extends DataObject
{
    public const ACTION_VIEW = 'View';
    public const ACTION_DOWNLOAD = 'Download';
    public const ACTION_PDF = 'Pdf';
    public const ACTION_STATUS = 'StatusChange';
    public const ACTION_LOCK = 'Lock';
    public const ACTION_UNLOCK = 'Unlock';
    public const ACTION_CREATE = 'Create';
    public const ACTION_EDIT = 'Edit';
    public const ACTION_UPLOAD = 'Upload';
    public const ACTION_SEARCH = 'Search';
    public const ACTION_REPORT = 'Report';
    public const ACTION_LOGIN = 'Login';
    public const ACTION_LOGIN_FAILED = 'LoginFailed';
    public const ACTION_OTP_SENT = 'OtpSent';
    public const ACTION_OTP_FAILED = 'OtpFailed';
    public const ACTION_OTP_OK = 'OtpVerified';

    private static string $table_name = 'EGov_AuditLogEntry';

    private static string $singular_name = 'Revizijski zapis';

    private static string $plural_name = 'Revizijski trag';

    private static array $db = [
        'Action' => 'Varchar(32)',
        'MemberID' => 'Int',
        'MemberName' => 'Varchar(255)',
        'RequestID' => 'Int',
        'TrackingCode' => 'Varchar(16)',
        'Details' => 'Text',
        'IP' => 'Varchar(45)',
        'UserAgent' => 'Varchar(255)',
    ];

    private static array $indexes = [
        'Action' => true,
        'MemberID' => true,
        'RequestID' => true,
        'Created' => true,
    ];

    private static string $default_sort = '"Created" DESC, "ID" DESC';

    private static array $summary_fields = [
        'Created.Nice' => 'Vrijeme',
        'ActionLabel' => 'Radnja',
        'MemberName' => 'Korisnik',
        'TrackingCode' => 'Predmet',
        'Details' => 'Detalji',
        'IP' => 'IP',
    ];

    private static array $searchable_fields = [
        'Action' => ['title' => 'Radnja', 'filter' => 'ExactMatchFilter'],
        'MemberName' => ['title' => 'Korisnik', 'filter' => 'PartialMatchFilter'],
        'TrackingCode' => ['title' => 'Kod predmeta', 'filter' => 'PartialMatchFilter'],
        'IP' => ['title' => 'IP', 'filter' => 'PartialMatchFilter'],
    ];

    public function getActionLabel(): string
    {
        return match ($this->Action) {
            self::ACTION_VIEW => 'Pregled predmeta',
            self::ACTION_DOWNLOAD => 'Preuzimanje priloga',
            self::ACTION_PDF => 'PDF zahtjeva / potvrde',
            self::ACTION_STATUS => 'Promjena statusa',
            self::ACTION_LOCK => 'Pokrenuta obrada (zaključano)',
            self::ACTION_UNLOCK => 'Otključano',
            self::ACTION_CREATE => 'Prijem na šalteru',
            self::ACTION_EDIT => 'Izmjena podataka',
            self::ACTION_UPLOAD => 'Upload priloga',
            self::ACTION_SEARCH => 'Pretraga po JMBG/ID',
            self::ACTION_REPORT => 'Izvještaj',
            self::ACTION_LOGIN => 'Prijava',
            self::ACTION_LOGIN_FAILED => 'Neuspjela prijava',
            self::ACTION_OTP_SENT => 'Poslan kod za prijavu',
            self::ACTION_OTP_FAILED => 'Pogrešan kod za prijavu',
            self::ACTION_OTP_OK => 'Kod za prijavu potvrđen',
            default => (string) $this->Action,
        };
    }

    protected function onBeforeWrite(): void
    {
        if ($this->isInDB()) {
            throw new \LogicException('Revizijski zapisi se ne mogu mijenjati.');
        }
        parent::onBeforeWrite();
    }

    public function canView($member = null): bool
    {
        return Permission::checkMember($member, ['ADMIN', ApplicationRequest::PERMISSION_AUDIT]);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
