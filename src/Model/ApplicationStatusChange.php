<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\RequestStatus;

/**
 * Immutable audit trail entry for every status transition.
 *
 * @property string $FromStatus
 * @property string $ToStatus
 * @property string|null $Note
 * @property string $ActorType  Citizen|Staff|System
 * @property bool $CitizenNotified
 * @property int $RequestID
 * @property int $MemberID
 * @method ApplicationRequest Request()
 * @method Member Member()
 */
class ApplicationStatusChange extends DataObject
{
    private static string $table_name = 'EGov_ApplicationStatusChange';

    private static string $singular_name = 'Promjena statusa';

    private static string $plural_name = 'Promjene statusa';

    private static array $db = [
        'FromStatus' => 'Varchar(32)',
        'ToStatus' => 'Varchar(32)',
        'Note' => 'Text',
        'ActorType' => "Enum('Citizen,Staff,System','Staff')",
        'CitizenNotified' => 'Boolean',
    ];

    private static array $has_one = [
        'Request' => ApplicationRequest::class,
        'Member' => Member::class,
    ];

    private static string $default_sort = '"Created" DESC, "ID" DESC';

    private static array $summary_fields = [
        'Created.Nice' => 'Vrijeme',
        'FromLabel' => 'Iz statusa',
        'ToLabel' => 'U status',
        'ActorLabel' => 'Izvršio',
        'Note' => 'Napomena',
        'CitizenNotified.Nice' => 'Građanin obaviješten',
    ];

    public function getFromLabel(): string
    {
        return RequestStatus::tryFrom((string) $this->FromStatus)?->label() ?? '—';
    }

    public function getToLabel(): string
    {
        return RequestStatus::tryFrom((string) $this->ToStatus)?->label() ?? '—';
    }

    public function getActorLabel(): string
    {
        return match ($this->ActorType) {
            'Citizen' => 'Podnosilac',
            'System' => 'Sistem',
            default => $this->Member()->exists() ? (string) $this->Member()->getName() : 'Referent',
        };
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
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
