<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Taksa / naknada vezana za proceduru (polja D2.x iz registra procedura).
 *
 * Amount semantics:
 *  - Amount only                → exact amount
 *  - Amount + AmountMax         → range (e.g. 70–200 KM depending on object area)
 *  - Amount + AdditionalUnitAmount → base + per additional unit
 *    (e.g. kopija katastarskog plana: 11 KM + 4 KM za svaku narednu parcelu)
 *
 * @property string $Title
 * @property string $Kind  MunicipalFee|HigherAuthorityFee|Compensation
 * @property string $LegalBasis
 * @property string $TariffNumber
 * @property float $Amount
 * @property float|null $AmountMax
 * @property float|null $AdditionalUnitAmount
 * @property string $AmountNote
 * @property string $RevenueType   Vrsta prihoda (6 cifara), npr. 722131
 * @property string $BudgetOrganization  Budžetska organizacija (7 cifara)
 * @property string $PaymentReference   Poziv na broj (opcionalno)
 * @property string $VariantKey
 * @property bool $IsMandatory
 * @property int $Sort
 * @property int $ProcedureID
 * @property int $PaymentAccountID
 * @method ProcedureDefinition Procedure()
 * @method PaymentAccount PaymentAccount()
 */
class FeeItem extends DataObject
{
    private static string $table_name = 'EGov_FeeItem';

    private static string $singular_name = 'Taksa / naknada';

    private static string $plural_name = 'Takse i naknade';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Kind' => "Enum('MunicipalFee,HigherAuthorityFee,Compensation','MunicipalFee')",
        'LegalBasis' => 'Text',
        'TariffNumber' => 'Varchar(20)',
        'Amount' => 'Decimal(10,2)',
        'AmountMax' => 'Decimal(10,2)',
        'AdditionalUnitAmount' => 'Decimal(10,2)',
        'AmountNote' => 'Varchar(255)',
        'RevenueType' => 'Varchar(6)',
        'BudgetOrganization' => 'Varchar(7)',
        'PaymentReference' => 'Varchar(10)',
        'VariantKey' => 'Varchar(50)',
        'IsMandatory' => 'Boolean(1)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Procedure' => ProcedureDefinition::class,
        'PaymentAccount' => PaymentAccount::class,
    ];

    private static string $default_sort = '"Sort" ASC, "ID" ASC';

    private static array $summary_fields = [
        'Title' => 'Naziv',
        'TariffNumber' => 'Tar. br.',
        'AmountLabel' => 'Iznos',
        'PaymentAccount.FormattedAccountNumber' => 'Račun',
        'RevenueType' => 'Vrsta prihoda',
        'VariantKey' => 'Varijanta',
    ];

    public function getKindLabel(): string
    {
        return match ($this->Kind) {
            'HigherAuthorityFee' => _t(self::class . '.KIND_HIGHER', 'Taksa viših organa vlasti'),
            'Compensation' => _t(self::class . '.KIND_COMP', 'Naknada'),
            default => _t(self::class . '.KIND_MUNICIPAL', 'Općinska/gradska administrativna taksa'),
        };
    }

    public function isRange(): bool
    {
        return $this->AmountMax !== null && (float) $this->AmountMax > (float) $this->Amount;
    }

    /**
     * Expected amount for $units chargeable units (parcels, copies...).
     * Returns [min, max] – identical values for exact amounts.
     *
     * @return array{0: float, 1: float}
     */
    public function expectedRange(int $units = 1): array
    {
        $base = (float) $this->Amount;
        $extra = max(0, $units - 1) * (float) ($this->AdditionalUnitAmount ?? 0.0);
        $min = round($base + $extra, 2);
        $max = $this->isRange() ? round((float) $this->AmountMax + $extra, 2) : $min;
        return [$min, $max];
    }

    public function getAmountLabel(): string
    {
        if ($this->AmountNote) {
            return (string) $this->AmountNote;
        }
        [$min, $max] = $this->expectedRange();
        $label = $min === $max
            ? self::km($min)
            : sprintf('%s – %s', self::km($min), self::km($max));
        if ((float) $this->AdditionalUnitAmount > 0) {
            $label .= ' + ' . self::km((float) $this->AdditionalUnitAmount) . ' '
                . _t(self::class . '.PER_UNIT', 'za svaku narednu jedinicu');
        }
        return $label;
    }

    public static function km(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' KM';
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ProcedureID', 'Sort']);
        $fields->replaceField(
            'PaymentAccountID',
            DropdownField::create(
                'PaymentAccountID',
                $this->fieldLabel('PaymentAccount'),
                PaymentAccount::get()->filter('IsActive', true)->map('ID', 'TitleWithAccount')
            )->setEmptyString('— odaberite račun —')
        );
        $fields->replaceField(
            'Kind',
            DropdownField::create('Kind', $this->fieldLabel('Kind'), [
                'MunicipalFee' => _t(self::class . '.KIND_MUNICIPAL', 'Općinska/gradska administrativna taksa'),
                'HigherAuthorityFee' => _t(self::class . '.KIND_HIGHER', 'Taksa viših organa vlasti'),
                'Compensation' => _t(self::class . '.KIND_COMP', 'Naknada'),
            ])
        );
        $fields->dataFieldByName('AmountMax')?->setDescription('Popuniti samo ako je iznos raspon (npr. 70–200 KM).');
        $fields->dataFieldByName('AdditionalUnitAmount')?->setDescription('Npr. 4 KM za svaku narednu parcelu.');
        $fields->dataFieldByName('VariantKey')?->setDescription(
            'Prazno = važi uvijek. Inače se primjenjuje samo kad zahtjev vrati isti ključ varijante (npr. Residential).'
        );
        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if ($this->RevenueType !== null && $this->RevenueType !== '' && !preg_match('/^\d{6}$/', (string) $this->RevenueType)) {
            $result->addFieldError('RevenueType', _t(self::class . '.REVENUE_FORMAT', 'Vrsta prihoda mora imati 6 cifara.'));
        }
        if ($this->BudgetOrganization !== null && $this->BudgetOrganization !== '' && !preg_match('/^\d{7}$/', (string) $this->BudgetOrganization)) {
            $result->addFieldError('BudgetOrganization', _t(self::class . '.BUDGET_FORMAT', 'Budžetska organizacija mora imati 7 cifara.'));
        }
        return $result;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(self::class . '.Title', 'Naziv takse/naknade'),
            'Kind' => _t(self::class . '.Kind', 'Vrsta'),
            'LegalBasis' => _t(self::class . '.LegalBasis', 'Pravni osnov (akt, član, službeno glasilo)'),
            'TariffNumber' => _t(self::class . '.TariffNumber', 'Tarifni broj'),
            'Amount' => _t(self::class . '.Amount', 'Iznos (KM) / minimalni iznos'),
            'AmountMax' => _t(self::class . '.AmountMax', 'Maksimalni iznos (KM)'),
            'AdditionalUnitAmount' => _t(self::class . '.AdditionalUnitAmount', 'Iznos po dodatnoj jedinici (KM)'),
            'AmountNote' => _t(self::class . '.AmountNote', 'Napomena o iznosu (prikaz)'),
            'RevenueType' => _t(self::class . '.RevenueType', 'Vrsta prihoda'),
            'BudgetOrganization' => _t(self::class . '.BudgetOrganization', 'Budžetska organizacija'),
            'PaymentReference' => _t(self::class . '.PaymentReference', 'Poziv na broj'),
            'VariantKey' => _t(self::class . '.VariantKey', 'Ključ varijante'),
            'IsMandatory' => _t(self::class . '.IsMandatory', 'Obavezna uplata'),
            'PaymentAccount' => _t(self::class . '.PaymentAccount', 'Račun za uplatu'),
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
