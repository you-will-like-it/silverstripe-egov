<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\SupplementReason;

/**
 * One requested document in a supplement round ("poziv za dopunu").
 * Immutable record – the basis of the per-referent supplement statistics.
 *
 * @property string $Reason
 * @property int $Round
 * @property int $RequestID
 * @property int $RequiredDocumentID
 * @property int $MemberID
 * @method ApplicationRequest Request()
 * @method RequiredDocument RequiredDocument()
 * @method Member Member()
 */
class SupplementItem extends DataObject
{
    private static string $table_name = 'EGov_SupplementItem';

    private static string $singular_name = 'Stavka dopune';

    private static string $plural_name = 'Stavke dopune';

    private static array $db = [
        'Reason' => "Enum('Missing,Illegible,WrongDocument,Expired,WrongPayment','Missing')",
        'Round' => 'Int',
    ];

    private static array $has_one = [
        'Request' => ApplicationRequest::class,
        'RequiredDocument' => RequiredDocument::class,
        'Member' => Member::class,
    ];

    private static string $default_sort = '"Round" DESC, "ID" ASC';

    private static array $summary_fields = [
        'Round' => 'Krug',
        'RequiredDocument.Title' => 'Dokument',
        'ReasonLabel' => 'Razlog',
        'Member.Name' => 'Zatražio',
        'Created.Nice' => 'Vrijeme',
    ];

    public function getReasonEnum(): SupplementReason
    {
        return SupplementReason::tryFrom((string) $this->Reason) ?? SupplementReason::Missing;
    }

    public function getReasonLabel(): string
    {
        return $this->getReasonEnum()->label();
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
