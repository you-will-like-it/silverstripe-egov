<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Katastarska općina (k.o.).
 *
 * @property string $Title
 * @property string $Code
 * @property string $Survey  New|Old
 * @property bool $IsActive
 * @property int $SiteConfigID
 */
class CadastralMunicipality extends DataObject
{
    public const SURVEY_NEW = 'New';
    public const SURVEY_OLD = 'Old';

    private static string $table_name = 'EGov_CadastralMunicipality';

    private static string $singular_name = 'Katastarska općina';

    private static string $plural_name = 'Katastarske općine';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Code' => 'Varchar(20)',
        'Survey' => "Enum('New,Old','New')",
        'IsActive' => 'Boolean(1)',
    ];

    private static array $has_one = [
        'SiteConfig' => SiteConfig::class,
    ];

    private static string $default_sort = '"Title" ASC';

    private static array $summary_fields = [
        'Title' => 'K.o.',
        'Code' => 'Šifra',
        'SurveyLabel' => 'Premjer',
    ];

    public function getSurveyLabel(): string
    {
        return $this->Survey === self::SURVEY_OLD
            ? _t(self::class . '.SURVEY_OLD', 'Stari premjer')
            : _t(self::class . '.SURVEY_NEW', 'Novi premjer');
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->SiteConfigID) {
            $this->SiteConfigID = (int) SiteConfig::current_site_config()->ID;
        }
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(self::class . '.Title', 'Naziv k.o.'),
            'Code' => _t(self::class . '.Code', 'Šifra k.o.'),
            'Survey' => _t(self::class . '.Survey', 'Premjer'),
            'IsActive' => _t(self::class . '.IsActive', 'Aktivna'),
        ]);
    }

    public function canView($member = null): bool
    {
        return true;
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
        return Permission::checkMember($member, 'EGOV_CONFIG');
    }
}
