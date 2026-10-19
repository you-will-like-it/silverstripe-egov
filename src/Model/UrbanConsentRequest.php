<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * 08-01 Zahtjev za izdavanje urbanističke saglasnosti
 * (Služba za prostorno uređenje i zaštitu okolice).
 *
 * Typed fields are real columns (filterable, reportable); the fee depends on
 * object type (Tarifni broj 11: stambeni 70–200 KM, stambeno-poslovni 100–300 KM ...),
 * so ObjectType is the fee variant key.
 *
 * @property string $ObjectType
 * @property string $ObjectCharacter
 * @property string $ObjectDescription
 * @property float $GrossFloorArea
 * @property string $FloorCount
 */
class UrbanConsentRequest extends ApplicationRequest
{
    private static string $table_name = 'EGov_UrbanConsentRequest';

    private static string $singular_name = 'Zahtjev za urbanističku saglasnost';

    private static string $plural_name = 'Zahtjevi za urbanističku saglasnost';

    private static array $db = [
        'ObjectType' => "Enum('Residential,ResidentialCommercial,Commercial,Other','Residential')",
        'ObjectCharacter' => "Enum('Permanent,Temporary','Permanent')",
        'ObjectDescription' => 'Varchar(255)',
        'GrossFloorArea' => 'Decimal(10,2)',
        'FloorCount' => 'Varchar(20)',
    ];

    /**
     * @return array<string, string>
     */
    public static function objectTypeOptions(): array
    {
        return [
            'Residential' => _t(self::class . '.TYPE_RES', 'Stambeni objekat'),
            'ResidentialCommercial' => _t(self::class . '.TYPE_RESCOM', 'Stambeno-poslovni objekat'),
            'Commercial' => _t(self::class . '.TYPE_COM', 'Poslovni objekat'),
            'Other' => _t(self::class . '.TYPE_OTHER', 'Drugi objekat'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function characterOptions(): array
    {
        return [
            'Permanent' => _t(self::class . '.CHAR_PERM', 'stalnog'),
            'Temporary' => _t(self::class . '.CHAR_TEMP', 'privremenog'),
        ];
    }

    public function getObjectTypeLabel(): string
    {
        return self::objectTypeOptions()[$this->ObjectType] ?? (string) $this->ObjectType;
    }

    public function getObjectCharacterLabel(): string
    {
        return self::characterOptions()[$this->ObjectCharacter] ?? (string) $this->ObjectCharacter;
    }

    public function getProcedureFormFields(): FieldList
    {
        $fields = FieldList::create(
            DropdownField::create('ObjectType', 'Vrsta objekta', self::objectTypeOptions())
                ->setEmptyString('— odaberite —'),
            TextField::create('ObjectDescription', 'Opis objekta / namjena')
                ->setDescription('Npr. individualni stambeni objekat sa garažom.'),
            DropdownField::create('ObjectCharacter', 'Karakter objekta', [
                'Permanent' => 'Stalni',
                'Temporary' => 'Privremeni',
            ]),
            NumericField::create('GrossFloorArea', 'Bruto građevinska površina (m²)')->setScale(2),
            TextField::create('FloorCount', 'Spratnost')->setDescription('Npr. P+1, S+P+1+Pk.'),
        );
        // generic YAML payload fields (if any are configured for 08-01) come after typed ones
        $fields->merge(parent::getProcedureFormFields());
        return $fields;
    }

    public function getProcedureRequiredFields(): array
    {
        return array_merge(['ObjectType', 'ObjectCharacter'], parent::getProcedureRequiredFields());
    }

    public function saveProcedureFormData(array $data): void
    {
        $this->ObjectType = array_key_exists((string) ($data['ObjectType'] ?? ''), self::objectTypeOptions())
            ? (string) $data['ObjectType']
            : 'Other';
        $this->ObjectCharacter = ($data['ObjectCharacter'] ?? '') === 'Temporary' ? 'Temporary' : 'Permanent';
        $this->ObjectDescription = mb_substr(trim((string) ($data['ObjectDescription'] ?? '')), 0, 255);
        $this->GrossFloorArea = is_numeric($data['GrossFloorArea'] ?? null) ? (float) $data['GrossFloorArea'] : 0.0;
        $this->FloorCount = mb_substr(trim((string) ($data['FloorCount'] ?? '')), 0, 20);
        parent::saveProcedureFormData($data);
    }

    public function getProcedureSummary(): ArrayList
    {
        $rows = ArrayList::create([
            ArrayData::create(['Label' => 'Vrsta objekta', 'Value' => $this->getObjectTypeLabel()]),
            ArrayData::create(['Label' => 'Opis objekta', 'Value' => $this->ObjectDescription ?: '—']),
            ArrayData::create(['Label' => 'Karakter objekta', 'Value' => ucfirst($this->getObjectCharacterLabel())]),
            ArrayData::create([
                'Label' => 'Bruto građevinska površina',
                'Value' => (float) $this->GrossFloorArea > 0
                    ? number_format((float) $this->GrossFloorArea, 2, ',', '.') . ' m²'
                    : '—',
            ]),
            ArrayData::create(['Label' => 'Spratnost', 'Value' => $this->FloorCount ?: '—']),
        ]);
        foreach (parent::getProcedureSummary() as $row) {
            $rows->push($row);
        }
        return $rows;
    }


    public function getFeeVariantKey(): ?string
    {
        return (string) $this->ObjectType ?: null;
    }

    public function getSubjectLine(): string
    {
        return 'Zahtjev za izdavanje urbanističke saglasnosti';
    }

    public function getPdfIntroText(): ?string
    {
        $service = (string) $this->Service()->Title ?: 'nadležnoj službi';
        return sprintf(
            'Obraćam/o se %s zahtjevom za izdavanje urbanističke saglasnosti za lokaciju-izgradnju %s, '
            . 'koji će biti %s karaktera i kojeg namjeravam/o graditi u %s, u ulici %s, '
            . 'na zemljištu označenom kao k.p. broj %s, upisanom u k.o. %s.',
            $service,
            mb_strtolower($this->ObjectDescription ?: $this->getObjectTypeLabel()),
            $this->getObjectCharacterLabel(),
            $this->LocationPlace ?: '________',
            $this->LocationStreet ?: '________',
            $this->CadastralParcelNew ?: ($this->CadastralParcelOld ?: '________'),
            $this->CadastralMunicipalityNew()->Title ?: ($this->CadastralMunicipalityOld()->Title ?: '________')
        );
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'ObjectType' => 'Vrsta objekta',
            'ObjectCharacter' => 'Karakter objekta',
            'ObjectDescription' => 'Opis objekta',
            'GrossFloorArea' => 'Bruto građevinska površina (m²)',
            'FloorCount' => 'Spratnost',
        ]);
    }
}
