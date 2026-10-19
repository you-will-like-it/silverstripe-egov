<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tenant;

use SilverStripe\Assets\Image;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;
use YouWillLikeIT\EGov\Model\CadastralMunicipality;
use YouWillLikeIT\EGov\Model\PaymentAccount;

/**
 * Parametri općine/grada (tenant) u Settings → e-Uprava.
 * Empty values fall back to YAML defaults in {@see TenantConfig}.
 *
 * @property string $EGovMunicipalityType
 * @property string $EGovMunicipalityName
 * @property string $EGovMunicipalityCode
 * @property string $EGovMunicipalityIdNumber
 * @property string $EGovCanton
 * @property string $EGovEntity
 * @property string $EGovTrackingPrefix
 * @property string $EGovAddress
 * @property string $EGovPhone
 * @property string $EGovEmail
 * @property string $EGovFromEmail
 * @property string $EGovPdfFooter
 * @property string $EGovPrivacyNotice
 * @property int $EGovLogoID
 * @method Image EGovLogo()
 * @extends Extension<SiteConfig>
 */
class TenantSiteConfigExtension extends Extension
{
    private static array $db = [
        'EGovMunicipalityType' => "Enum('City,Municipality','Municipality')",
        'EGovMunicipalityName' => 'Varchar(100)',
        'EGovMunicipalityCode' => 'Varchar(3)',
        'EGovMunicipalityIdNumber' => 'Varchar(13)',
        'EGovCanton' => 'Varchar(100)',
        'EGovEntity' => 'Varchar(100)',
        'EGovTrackingPrefix' => 'Varchar(5)',
        'EGovAddress' => 'Varchar(255)',
        'EGovPhone' => 'Varchar(50)',
        'EGovEmail' => 'Varchar(255)',
        'EGovFromEmail' => 'Varchar(255)',
        'EGovPdfFooter' => 'Text',
        'EGovPrivacyNotice' => 'Text',
    ];

    private static array $has_one = [
        'EGovLogo' => Image::class,
    ];

    private static array $has_many = [
        'EGovPaymentAccounts' => PaymentAccount::class . '.SiteConfig',
        'EGovCadastralMunicipalities' => CadastralMunicipality::class . '.SiteConfig',
    ];

    private static array $owns = ['EGovLogo'];

    protected function updateCMSFields(FieldList $fields): void
    {
        if (!Permission::check('EGOV_CONFIG')) {
            return;
        }

        $tab = 'Root.EGov';
        $fields->addFieldsToTab($tab, [
            HeaderField::create('EGovHeadGeneral', 'Osnovni podaci općine / grada', 3),
            DropdownField::create('EGovMunicipalityType', 'Vrsta jedinice lokalne samouprave', [
                'City' => 'Grad',
                'Municipality' => 'Općina',
            ]),
            TextField::create('EGovMunicipalityName', 'Naziv (bez "Grad"/"Općina")')
                ->setDescription('Npr. Srebrenik'),
            TextField::create('EGovMunicipalityCode', 'Šifra općine (uplatnice)')
                ->setDescription('Trocifrena šifra općine na uplatnicama javnih prihoda, npr. 084.')
                ->setMaxLength(3),
            TextField::create('EGovMunicipalityIdNumber', 'Identifikacioni broj općine'),
            TextField::create('EGovCanton', 'Kanton'),
            TextField::create('EGovEntity', 'Entitet'),
            TextField::create('EGovAddress', 'Adresa'),
            TextField::create('EGovPhone', 'Telefon'),
            TextField::create('EGovEmail', 'Službeni email'),
            TextField::create('EGovFromEmail', 'Pošiljalac obavještenja (From)')
                ->setDescription('Mora biti adresa dozvoljena na mail serveru (SPF/DKIM).'),
            TextField::create('EGovTrackingPrefix', 'Prefiks koda predmeta')
                ->setDescription('Npr. SRB → SRB-7K4Q9M2X')
                ->setMaxLength(5),
            UploadField::create('EGovLogo', 'Grb (PDF zaglavlje, portal)')
                ->setFolderName('egov-javno')
                ->setAllowedFileCategories('image/supported'),
            TextareaField::create('EGovPdfFooter', 'Podnožje PDF-a')->setRows(2),
            TextareaField::create('EGovPrivacyNotice', 'Obavještenje o obradi ličnih podataka (portal)')->setRows(5),
            HeaderField::create('EGovHeadAccounts', 'Računi za uplatu taksi, naknada i renti', 3),
            GridField::create(
                'EGovPaymentAccounts',
                'Računi',
                $this->getOwner()->EGovPaymentAccounts(),
                GridFieldConfig_RecordEditor::create()
            ),
            HeaderField::create('EGovHeadKo', 'Katastarske općine', 3),
            GridField::create(
                'EGovCadastralMunicipalities',
                'Katastarske općine',
                $this->getOwner()->EGovCadastralMunicipalities(),
                GridFieldConfig_RecordEditor::create(50)
            ),
        ]);
        $fields->fieldByName($tab)?->setTitle('e-Uprava');
    }
}
