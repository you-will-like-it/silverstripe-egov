<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use JsonException;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Permission;
use YouWillLikeIT\EGov\Enum\VerificationLight;

/**
 * Prilog uz zahtjev. The physical file is a protected (never published) asset;
 * downloads go through {@see \YouWillLikeIT\EGov\Controller\SecureFileController}.
 *
 * @property string $Kind
 * @property string $OriginalFilename
 * @property string $MimeType
 * @property int $FileSize
 * @property string $Sha256
 * @property bool $IsSupplement
 * @property string $VerificationLight
 * @property string|null $VerificationReport
 * @property string|null $VerifiedAt
 * @property string|null $SuggestedReason
 * @property int $RequestID
 * @property int $FileID
 * @property int $RequiredDocumentID
 * @method ApplicationRequest Request()
 * @method File File()
 * @method RequiredDocument RequiredDocument()
 */
class ApplicationAttachment extends DataObject
{
    /** Uploaded by the citizen through the portal. */
    public const KIND_CITIZEN = 'Citizen';
    /** Scanned/uploaded by a referent (counter intake). */
    public const KIND_STAFF = 'Staff';
    /** Copy of the issued act (rješenje, uvjerenje …) – never sent to the citizen. */
    public const KIND_RESULT = 'Result';

    private static string $table_name = 'EGov_ApplicationAttachment';

    private static string $singular_name = 'Prilog';

    private static string $plural_name = 'Prilozi';

    private static array $db = [
        'Kind' => "Enum('Citizen,Staff,Result','Citizen')",
        'OriginalFilename' => 'Varchar(255)',
        'MimeType' => 'Varchar(100)',
        'FileSize' => 'Int',
        'Sha256' => 'Varchar(64)',
        'IsSupplement' => 'Boolean',
        'VerificationLight' => "Enum('Pending,Green,Yellow,Red,Error','Pending')",
        'VerificationReport' => 'Text',
        'VerifiedAt' => 'Datetime',
        'SuggestedReason' => 'Varchar(32)',
    ];

    private static array $has_one = [
        'Request' => ApplicationRequest::class,
        'File' => File::class,
        'RequiredDocument' => RequiredDocument::class,
        'UploadedBy' => \SilverStripe\Security\Member::class,
    ];

    private static array $cascade_deletes = ['File'];

    private static array $indexes = [
        'VerificationLight' => true,
        'Sha256' => true,
    ];

    private static string $default_sort = '"Created" ASC';

    private static array $casting = [
        'LightBadge' => 'HTMLFragment',
        'DownloadLink' => 'HTMLFragment',
    ];

    private static array $summary_fields = [
        'LightBadge' => 'AI',
        'RequiredDocument.Title' => 'Vrsta dokumenta',
        'OriginalFilename' => 'Datoteka',
        'Created.Nice' => 'Priložen',
    ];

    public function getTitle(): string
    {
        return sprintf('%s – %s', $this->getDocumentLabel(), $this->OriginalFilename);
    }

    public function getDocumentLabel(): string
    {
        if ($this->Kind === self::KIND_RESULT) {
            return 'Izdati akt';
        }
        return (string) ($this->RequiredDocument()->Title ?: 'Prilog');
    }

    public function getKindLabel(): string
    {
        return match ($this->Kind) {
            self::KIND_STAFF => 'Šalter',
            self::KIND_RESULT => 'Izdati akt',
            default => 'Portal',
        };
    }

    public function getLight(): VerificationLight
    {
        return VerificationLight::tryFrom((string) $this->VerificationLight) ?? VerificationLight::Pending;
    }

    public function getLightIcon(): string
    {
        return $this->getLight()->icon();
    }

    public function getLightLabel(): string
    {
        return $this->getLight()->label();
    }

    public function getLightBadge(): DBHTMLText
    {
        $light = $this->getLight();
        return DBField::create_field('HTMLFragment', sprintf(
            '<span title="%1$s" aria-label="%1$s">%2$s</span>',
            htmlspecialchars($light->label(), ENT_QUOTES),
            $light->icon()
        ));
    }

    public function getDownloadURL(): string
    {
        return Controller::join_links('egov-datoteke', 'prilog', (string) $this->ID);
    }

    public function getDownloadLink(): DBHTMLText
    {
        return DBField::create_field('HTMLFragment', sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">'
            . '<span class="font-icon-down-circled" aria-hidden="true"></span> Preuzmi</a>',
            htmlspecialchars($this->getDownloadURL(), ENT_QUOTES)
        ));
    }

    public function getHumanFileSize(): string
    {
        $size = (int) $this->FileSize;
        return $size >= 1048576
            ? number_format($size / 1048576, 1, ',', '.') . ' MB'
            : number_format($size / 1024, 0, ',', '.') . ' kB';
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        $raw = (string) $this->VerificationReport;
        if ($raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Checks for the CMS report template.
     *
     * @return ArrayList<ArrayData>
     */
    public function getReportChecks(): ArrayList
    {
        $list = ArrayList::create();
        foreach ((array) ($this->getReport()['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $light = VerificationLight::tryFrom((string) ($check['light'] ?? '')) ?? VerificationLight::Yellow;
            $list->push(ArrayData::create([
                'Label' => (string) ($check['label'] ?? ''),
                'Message' => (string) ($check['message'] ?? ''),
                'Icon' => $light->icon(),
                'Source' => (string) ($check['source'] ?? ''),
            ]));
        }
        return $list;
    }

    public function getReportSummary(): string
    {
        return (string) ($this->getReport()['summary'] ?? '');
    }

    public function getDetectedDocumentType(): string
    {
        return (string) ($this->getReport()['detected_type'] ?? '');
    }

    public function canView($member = null): bool
    {
        return $this->Request()->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
