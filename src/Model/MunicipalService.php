<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Općinska/gradska služba (organizaciona jedinica), npr.
 * "08 – Služba za prostorno uređenje i zaštitu okolice".
 *
 * Referents are linked through Groups; a referent sees only requests of
 * services whose groups he belongs to.
 *
 * @property string $Code
 * @property string $Title
 * @property string $Department
 * @property string $Email
 * @property string $Phone
 * @property int $Sort
 * @method HasManyList<ProcedureDefinition> Procedures()
 * @method ManyManyList<Group> Groups()
 */
class MunicipalService extends DataObject
{
    private static string $table_name = 'EGov_MunicipalService';

    private static string $singular_name = 'Služba';

    private static string $plural_name = 'Službe';

    private static array $db = [
        'Code' => 'Varchar(10)',
        'Title' => 'Varchar(255)',
        'Department' => 'Varchar(255)',
        'Email' => 'Varchar(255)',
        'Phone' => 'Varchar(50)',
        'Sort' => 'Int',
    ];

    private static array $has_many = [
        'Procedures' => ProcedureDefinition::class,
    ];

    private static array $many_many = [
        'Groups' => Group::class,
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static string $default_sort = '"Sort" ASC, "Code" ASC';

    private static array $summary_fields = [
        'Code' => 'Šifra',
        'Title' => 'Naziv',
        'Email' => 'Email',
    ];

    public function getTitleWithCode(): string
    {
        return sprintf('%s – %s', $this->Code, $this->Title);
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Code' => _t(self::class . '.Code', 'Šifra službe'),
            'Title' => _t(self::class . '.Title', 'Naziv službe'),
            'Department' => _t(self::class . '.Department', 'Odsjek (opcionalno)'),
            'Email' => _t(self::class . '.Email', 'Email službe (obavještenja o novim predmetima)'),
            'Phone' => _t(self::class . '.Phone', 'Telefon'),
            'Sort' => _t(self::class . '.Sort', 'Redoslijed'),
            'Groups' => _t(self::class . '.Groups', 'Grupe referenata'),
            'Procedures' => _t(self::class . '.Procedures', 'Procedure'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('Code')?->setDescription('Šifra iz registra administrativnih procedura, npr. 08.');
        return $fields;
    }

    /**
     * IDs of services the member may see.
     * Supervisors (EGOV_SUPERVISOR) and admins get every service unless $strict,
     * in which case only real group membership counts (= services he may work on).
     *
     * @return list<int>
     */
    public static function idsForMember(?Member $member = null, bool $strict = false): array
    {
        $member ??= Security::getCurrentUser();
        if (!$member) {
            return [];
        }

        if (!$strict && Permission::checkMember($member, ['ADMIN', 'EGOV_SUPERVISOR'])) {
            return array_map('intval', self::get()->sort(null)->column('ID'));
        }

        // DataList::getIDList() is deprecated since 6.2 -> sort(null)->column('ID')
        $groupIds = $member->Groups()->sort(null)->column('ID');
        if ($groupIds === []) {
            return [];
        }

        return array_map(
            'intval',
            self::get()->filter('Groups.ID', $groupIds)->sort(null)->columnUnique('ID')
        );
    }

    public function canView($member = null): bool
    {
        return Permission::checkMember($member, ['EGOV_REFERENT', 'EGOV_SUPERVISOR', 'EGOV_CONFIG']);
    }

    public function canEdit($member = null): bool
    {
        return Permission::checkMember($member, 'EGOV_CONFIG');
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::checkMember($member, 'EGOV_CONFIG');
    }

    public function canDelete($member = null): bool
    {
        return Permission::checkMember($member, 'EGOV_CONFIG') && !$this->Procedures()->exists();
    }
}
