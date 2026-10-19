<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Dokumentacija koja se prilaže uz zahtjev (polja DB! iz registra procedura).
 *
 * @property string $DocumentKey
 * @property string $Title
 * @property string $Issuer
 * @property string $DocumentForm
 * @property bool $IsMandatory
 * @property bool $IsPaymentProof
 * @property bool $ObtainedExOfficio
 * @property string $AiHint
 * @property int $Sort
 * @property int $ProcedureID
 * @method ProcedureDefinition Procedure()
 */
class RequiredDocument extends DataObject
{
    private static string $table_name = 'EGov_RequiredDocument';

    private static string $singular_name = 'Potreban dokument';

    private static string $plural_name = 'Potrebni dokumenti';

    private static array $db = [
        'DocumentKey' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Issuer' => 'Varchar(255)',
        'DocumentForm' => "Enum('Original,CertifiedCopy,Copy,Inspection','Copy')",
        'IsMandatory' => 'Boolean(1)',
        'IsPaymentProof' => 'Boolean',
        'ObtainedExOfficio' => 'Boolean',
        'AiHint' => 'Text',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Procedure' => ProcedureDefinition::class,
    ];

    private static array $indexes = [
        'ProcedureKey' => ['type' => 'unique', 'columns' => ['ProcedureID', 'DocumentKey']],
    ];

    private static string $default_sort = '"Sort" ASC, "ID" ASC';

    private static array $summary_fields = [
        'Title' => 'Dokument',
        'Issuer' => 'Izdaje',
        'DocumentFormLabel' => 'Forma',
        'IsMandatory.Nice' => 'Obavezan',
        'IsPaymentProof.Nice' => 'Dokaz o uplati',
    ];

    /**
     * @return array<string, string>
     */
    public static function formOptions(): array
    {
        return [
            'Original' => _t(self::class . '.FORM_ORIGINAL', 'Original'),
            'CertifiedCopy' => _t(self::class . '.FORM_CERTIFIED', 'Ovjerena kopija'),
            'Copy' => _t(self::class . '.FORM_COPY', 'Kopija'),
            'Inspection' => _t(self::class . '.FORM_INSPECTION', 'Na uvid'),
        ];
    }

    public function getDocumentFormLabel(): string
    {
        return self::formOptions()[$this->DocumentForm] ?? (string) $this->DocumentForm;
    }

    /**
     * Whether the citizen has to upload it (ex-officio documents are fetched by the service).
     */
    public function isCitizenUpload(): bool
    {
        return !$this->ObtainedExOfficio;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ProcedureID', 'Sort']);
        $fields->replaceField(
            'DocumentForm',
            DropdownField::create('DocumentForm', $this->fieldLabel('DocumentForm'), self::formOptions())
        );
        $fields->dataFieldByName('DocumentKey')?->setDescription('Tehnički ključ (a-z, 0-9, crtica), npr. kopija-katastarskog-plana.');
        $fields->dataFieldByName('AiHint')?->setDescription(
            'Opis po kojem AI prepoznaje dokument (ključne riječi, izgled, izdavalac). Ne unositi lične podatke.'
        );
        return $fields;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->DocumentKey && $this->Title) {
            $this->DocumentKey = self::slug((string) $this->Title);
        }
    }

    public static function slug(string $value): string
    {
        $map = ['č' => 'c', 'ć' => 'c', 'đ' => 'dj', 'š' => 's', 'ž' => 'z', 'Č' => 'c', 'Ć' => 'c', 'Đ' => 'dj', 'Š' => 's', 'Ž' => 'z'];
        $value = strtolower(strtr($value, $map));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        return substr(trim($value, '-'), 0, 64);
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'DocumentKey' => _t(self::class . '.DocumentKey', 'Ključ'),
            'Title' => _t(self::class . '.Title', 'Naziv dokumenta'),
            'Issuer' => _t(self::class . '.Issuer', 'Ko izdaje dokument'),
            'DocumentForm' => _t(self::class . '.DocumentForm', 'Forma dokumenta'),
            'IsMandatory' => _t(self::class . '.IsMandatory', 'Obavezan prilog'),
            'IsPaymentProof' => _t(self::class . '.IsPaymentProof', 'Ovo je dokaz o uplati (provjera uplatnice)'),
            'ObtainedExOfficio' => _t(self::class . '.ObtainedExOfficio', 'Pribavlja služba po službenoj dužnosti'),
            'AiHint' => _t(self::class . '.AiHint', 'Uputa za AI prepoznavanje'),
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
