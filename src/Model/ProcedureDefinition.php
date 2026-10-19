<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Permission;
use YouWillLikeIT\EGov\Enum\DeadlineCategory;
use YouWillLikeIT\EGov\Procedure\ProcedureRegistry;

/**
 * Administrativni postupak iz registra procedura (npr. "08-01 Urbanistička saglasnost").
 *
 * DB holds what the municipality changes (fees, documents, texts);
 * YAML ({@see ProcedureRegistry}) holds what developers change (payload field schema,
 * deadline overrides).
 *
 * @property string $Code
 * @property string $Title
 * @property string $Summary
 * @property string $LegalBasis
 * @property string $DeadlineCategory
 * @property string $RequestClass
 * @property bool $RequiresLocation
 * @property bool $AiCheckEnabled
 * @property bool $IsActive
 * @property string $ResultDocumentTitle
 * @property string $ValidityNote
 * @property string $ServiceClass
 * @property int $Sort
 * @property int $ServiceID
 * @method MunicipalService Service()
 * @method HasManyList<FeeItem> Fees()
 * @method HasManyList<RequiredDocument> RequiredDocuments()
 */
class ProcedureDefinition extends DataObject
{
    private static string $table_name = 'EGov_ProcedureDefinition';

    private static string $singular_name = 'Procedura';

    private static string $plural_name = 'Procedure';

    private static array $db = [
        'Code' => 'Varchar(16)',
        'Title' => 'Varchar(255)',
        'Summary' => 'Text',
        'LegalBasis' => 'Text',
        'DeadlineCategory' => "Enum('Simple,Examination,Certificate','Simple')",
        'RequestClass' => 'Varchar(255)',
        'RequiresLocation' => 'Boolean',
        'AiCheckEnabled' => 'Boolean',
        'IsActive' => 'Boolean(1)',
        'ResultDocumentTitle' => 'Varchar(255)',
        'ValidityNote' => 'Varchar(255)',
        'ServiceClass' => 'Varchar(32)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Service' => MunicipalService::class,
    ];

    private static array $has_many = [
        'Fees' => FeeItem::class,
        'RequiredDocuments' => RequiredDocument::class,
    ];

    private static array $cascade_deletes = ['Fees', 'RequiredDocuments'];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static string $default_sort = '"Code" ASC';

    private static array $summary_fields = [
        'Code' => 'Šifra',
        'Title' => 'Naziv',
        'Service.Title' => 'Služba',
        'IsActive.Nice' => 'Aktivna',
    ];

    private static array $searchable_fields = ['Code', 'Title', 'ServiceID', 'IsActive'];

    public function getTitleWithCode(): string
    {
        return sprintf('%s – %s', $this->Code, $this->Title);
    }

    public function getDeadlineCategoryEnum(): DeadlineCategory
    {
        return DeadlineCategory::tryFrom((string) $this->DeadlineCategory) ?? DeadlineCategory::Simple;
    }

    /**
     * @return class-string<ApplicationRequest>
     */
    public function getRequestClassName(): string
    {
        $class = (string) $this->RequestClass;
        if ($class !== '' && is_a($class, ApplicationRequest::class, true)) {
            return $class;
        }
        return ApplicationRequest::class;
    }

    /**
     * Documents the citizen has to upload (ex-officio ones excluded).
     *
     * @return HasManyList<RequiredDocument>
     */
    public function citizenDocuments(): HasManyList
    {
        return $this->RequiredDocuments()->filter('ObtainedExOfficio', false);
    }

    public function getPortalLink(): string
    {
        return ProcedureRegistry::singleton()->portalLink((string) $this->Code);
    }

    public static function getByCode(string $code): ?self
    {
        $record = self::get()->filter('Code', $code)->first();
        return $record instanceof self ? $record : null;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Sort']);

        $classes = [];
        foreach (ClassInfo::subclassesFor(ApplicationRequest::class) as $class) {
            $classes[$class] = singleton($class)->i18n_singular_name() . ' (' . ClassInfo::shortName($class) . ')';
        }
        $fields->replaceField(
            'RequestClass',
            DropdownField::create('RequestClass', $this->fieldLabel('RequestClass'), $classes)
                ->setDescription('Klasa modela zahtjeva. Generički zahtjev koristi FormPayload šemu iz YAML-a.')
        );
        $fields->replaceField(
            'DeadlineCategory',
            DropdownField::create('DeadlineCategory', $this->fieldLabel('DeadlineCategory'), DeadlineCategory::options())
                ->setDescription('Zakonski rok po ZUP FBiH; dani se podešavaju u YAML-u (po proceduri).')
        );
        $fields->replaceField(
            'ServiceID',
            DropdownField::create('ServiceID', $this->fieldLabel('Service'), MunicipalService::get()->map('ID', 'TitleWithCode'))
        );
        $fields->dataFieldByName('Code')?->setDescription('Šifra iz registra administrativnih procedura, npr. 08-01.');
        $fields->dataFieldByName('ServiceClass')?->setDescription('Klasifikacijska oznaka predmeta, npr. UP-I.');

        foreach (['Fees', 'RequiredDocuments'] as $relation) {
            $grid = $fields->dataFieldByName($relation);
            if ($grid instanceof GridField) {
                $config = GridFieldConfig_RecordEditor::create();
                $grid->setConfig($config);
            }
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!preg_match('/^\d{2}-\d{2,3}$/', (string) $this->Code)) {
            $result->addFieldError('Code', _t(self::class . '.CODE_FORMAT', 'Šifra mora biti u formatu SS-NN (npr. 08-01).'));
        }
        if (!$this->ServiceID) {
            $result->addFieldError('ServiceID', _t(self::class . '.SERVICE_REQUIRED', 'Odaberite službu.'));
        }
        return $result;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Code' => _t(self::class . '.Code', 'Šifra procedure'),
            'Title' => _t(self::class . '.Title', 'Naziv administrativnog postupka'),
            'Summary' => _t(self::class . '.Summary', 'Kratak opis za građane'),
            'LegalBasis' => _t(self::class . '.LegalBasis', 'Pravni osnov'),
            'DeadlineCategory' => _t(self::class . '.DeadlineCategory', 'Kategorija roka'),
            'RequestClass' => _t(self::class . '.RequestClass', 'Tip zahtjeva'),
            'RequiresLocation' => _t(self::class . '.RequiresLocation', 'Zahtijeva podatke o lokaciji (k.p., k.o.)'),
            'IsActive' => _t(self::class . '.IsActive', 'Dostupna na portalu'),
            'AiCheckEnabled' => _t(self::class . '.AiCheckEnabled', 'AI prepoznavanje vrste dokumenta (opcionalno; provjera uplatnice radi uvijek)'),
            'ResultDocumentTitle' => _t(self::class . '.ResultDocumentTitle', 'Naziv akta koji se izdaje'),
            'ValidityNote' => _t(self::class . '.ValidityNote', 'Vrijeme važenja dokumenta'),
            'ServiceClass' => _t(self::class . '.ServiceClass', 'Klasifikacijska oznaka'),
            'Service' => _t(self::class . '.Service', 'Služba'),
            'Fees' => _t(self::class . '.Fees', 'Takse i naknade'),
            'RequiredDocuments' => _t(self::class . '.RequiredDocuments', 'Potrebna dokumentacija'),
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
        return Permission::checkMember($member, 'EGOV_CONFIG')
            && !ApplicationRequest::get()->filter('ProcedureID', $this->ID)->exists();
    }
}
