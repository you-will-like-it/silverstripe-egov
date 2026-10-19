<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * 03-38 Zahtjev za izdavanje kopije katastarskog plana
 * (Služba za geodetske i imovinsko-pravne poslove).
 *
 * Fee: 11 KM + 4 KM za svaku narednu parcelu → ParcelCount drives AdditionalUnitAmount.
 *
 * @property int $ParcelCount
 * @property string $Purpose
 */
class CadastralPlanCopyRequest extends ApplicationRequest
{
    private static string $table_name = 'EGov_CadastralPlanCopyRequest';

    private static string $singular_name = 'Zahtjev za kopiju katastarskog plana';

    private static string $plural_name = 'Zahtjevi za kopiju katastarskog plana';

    private static array $db = [
        'ParcelCount' => 'Int',
        'Purpose' => "Enum('Construction,Legalisation,Court,Sale,Other','Other')",
    ];

    private static array $defaults = [
        'ParcelCount' => 1,
    ];

    /**
     * @return array<string, string>
     */
    public static function purposeOptions(): array
    {
        return [
            'Construction' => 'Gradnja (urbanistička saglasnost / odobrenje za građenje)',
            'Legalisation' => 'Legalizacija',
            'Court' => 'Sud / notar',
            'Sale' => 'Kupoprodaja',
            'Other' => 'Ostalo',
        ];
    }

    public function getProcedureFormFields(): FieldList
    {
        $fields = FieldList::create(
            NumericField::create('ParcelCount', 'Broj parcela')
                ->setValue(max(1, (int) $this->ParcelCount))
                ->setDescription('Taksa: 11 KM za prvu + 4 KM za svaku narednu parcelu.'),
            OptionsetField::create('Purpose', 'Svrha', self::purposeOptions(), 'Other'),
        );
        $fields->merge(parent::getProcedureFormFields());
        return $fields;
    }

    public function getProcedureRequiredFields(): array
    {
        return array_merge(['ParcelCount'], parent::getProcedureRequiredFields());
    }

    public function saveProcedureFormData(array $data): void
    {
        $this->ParcelCount = max(1, min(500, (int) ($data['ParcelCount'] ?? 1)));
        $purpose = (string) ($data['Purpose'] ?? 'Other');
        $this->Purpose = array_key_exists($purpose, self::purposeOptions()) ? $purpose : 'Other';
        parent::saveProcedureFormData($data);
    }

    public function getProcedureSummary(): ArrayList
    {
        $rows = ArrayList::create([
            ArrayData::create(['Label' => 'Broj parcela', 'Value' => (string) max(1, (int) $this->ParcelCount)]),
            ArrayData::create(['Label' => 'Svrha', 'Value' => self::purposeOptions()[$this->Purpose] ?? '—']),
        ]);
        foreach (parent::getProcedureSummary() as $row) {
            $rows->push($row);
        }
        return $rows;
    }


    public function getFeeUnitCount(): int
    {
        return max(1, (int) $this->ParcelCount);
    }

    public function getSubjectLine(): string
    {
        return 'Zahtjev za izdavanje kopije katastarskog plana';
    }

    public function getPdfIntroText(): ?string
    {
        return sprintf(
            'Podnosim zahtjev za izdavanje kopije katastarskog plana za %s, k.o. %s (broj parcela: %d), u svrhu: %s.',
            $this->CadastralParcelNew ? 'k.č. ' . $this->CadastralParcelNew : 'k.č. ' . ($this->CadastralParcelOld ?: '________'),
            $this->CadastralMunicipalityNew()->Title ?: ($this->CadastralMunicipalityOld()->Title ?: '________'),
            max(1, (int) $this->ParcelCount),
            mb_strtolower(self::purposeOptions()[$this->Purpose] ?? 'ostalo')
        );
    }
}
