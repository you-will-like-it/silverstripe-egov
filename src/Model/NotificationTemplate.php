<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * CMS-editable email / notice texts (one record per key).
 *
 * Structure of every message:  Intro  →  structured block (generated, not editable:
 * document list, payment data, buttons)  →  LegalNotice  →  Outro.
 * LegalNotice is "semi-fixed": editable per municipality by EGOV_CONFIG (lawyer),
 * never per case by a referent.
 *
 * Defaults live in YAML (`NotificationTemplate.defaults`) and are copied into the
 * database by `sake tasks:egov-seed`; a missing DB record falls back to YAML.
 *
 * @property string $TemplateKey
 * @property string $Title
 * @property string $Subject
 * @property string $Intro
 * @property string $LegalNotice
 * @property string $Outro
 */
class NotificationTemplate extends DataObject
{
    private static string $table_name = 'EGov_NotificationTemplate';

    private static string $singular_name = 'Tekst obavještenja';

    private static string $plural_name = 'Tekstovi obavještenja';

    private static array $db = [
        'TemplateKey' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Subject' => 'Varchar(255)',
        'Intro' => 'Text',
        'LegalNotice' => 'Text',
        'Outro' => 'Text',
    ];

    private static array $indexes = [
        'TemplateKey' => ['type' => 'unique', 'columns' => ['TemplateKey']],
    ];

    private static string $default_sort = '"TemplateKey" ASC';

    private static array $summary_fields = [
        'Title' => 'Obavještenje',
        'TemplateKey' => 'Ključ',
        'Subject' => 'Naslov emaila',
    ];

    /**
     * @config
     * @var array<string, array<string, string>>
     */
    private static array $defaults_by_key = [];

    /**
     * Placeholders available in Subject / Intro / LegalNotice / Outro.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        '{Ime}' => 'Ime i prezime / naziv podnosioca',
        '{KodPredmeta}' => 'Kod predmeta (npr. SRB-7K4Q9M2X)',
        '{BrojProtokola}' => 'Broj protokola',
        '{Procedura}' => 'Naziv procedure',
        '{Sluzba}' => 'Naziv službe',
        '{Status}' => 'Novi status',
        '{DatumPodnosenja}' => 'Datum podnošenja',
        '{RokRjesavanja}' => 'Zakonski rok za rješavanje',
        '{RokZaDopunu}' => 'Datum do kojeg se dostavlja dopuna',
        '{BrojDanaDopuna}' => 'Broj dana za dopunu',
        '{RazlogOdbacivanja}' => 'Razlog odbacivanja (iz zatvorene liste)',
        '{Opcina}' => 'Naziv općine / grada',
        '{LinkStatusa}' => 'Adresa stranice za provjeru statusa',
    ];

    public static function forKey(string $key): self
    {
        $record = self::get()->filter('TemplateKey', $key)->first();
        if ($record instanceof self) {
            return $record;
        }
        $defaults = (array) (Config::inst()->get(self::class, 'defaults_by_key')[$key] ?? []);
        return self::create([
            'TemplateKey' => $key,
            'Title' => (string) ($defaults['title'] ?? $key),
            'Subject' => (string) ($defaults['subject'] ?? ''),
            'Intro' => (string) ($defaults['intro'] ?? ''),
            'LegalNotice' => (string) ($defaults['legal'] ?? ''),
            'Outro' => (string) ($defaults['outro'] ?? ''),
        ]);
    }

    public static function statusKey(RequestStatus $status): string
    {
        return 'status_' . $status->value;
    }

    /**
     * @return array<string, string>
     */
    public static function variablesFor(?ApplicationRequest $request, array $extra = []): array
    {
        $tenant = TenantConfig::singleton();
        $vars = ['{Opcina}' => $tenant->fullName()];
        if ($request) {
            $vars += [
                '{Ime}' => (string) $request->ApplicantFullName,
                '{KodPredmeta}' => (string) $request->TrackingCode,
                '{BrojProtokola}' => (string) ($request->ProtocolNumber ?: '—'),
                '{Procedura}' => (string) $request->Procedure()->Title,
                '{Sluzba}' => (string) $request->Service()->Title,
                '{Status}' => $request->getPublicStatusLabel(),
                '{DatumPodnosenja}' => $request->getSubmittedAtFormatted(),
                '{RokRjesavanja}' => $request->getDecisionDueFormatted(),
                '{RokZaDopunu}' => $request->getSupplementDueFormatted(),
                '{RazlogOdbacivanja}' => $request->getDismissalReasonEnum()->label(),
                '{LinkStatusa}' => $request->getAbsoluteStatusPageLink(),
            ];
        }
        return array_merge($vars, $extra);
    }

    /**
     * Plain-text rendering with placeholders replaced.
     *
     * @param array<string, string> $vars
     */
    public function renderText(string $field, array $vars): string
    {
        return trim(strtr((string) $this->getField($field), $vars));
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('TemplateKey', ReadonlyField::create('TemplateKey', 'Ključ'));
        $list = '';
        foreach (self::PLACEHOLDERS as $ph => $desc) {
            $list .= sprintf('<li><code>%s</code> – %s</li>', htmlspecialchars($ph), htmlspecialchars($desc));
        }
        $fields->addFieldToTab('Root.Main', LiteralField::create('Placeholders', '<div class="alert alert-info"><strong>Dostupne oznake:</strong><ul>' . $list . '</ul>'
            . 'Lista traženih dokumenata, podaci za uplatu i dugmad se ubacuju automatski između uvoda i pravne napomene.</div>'), 'Title');
        $fields->dataFieldByName('LegalNotice')?->setDescription('Pravna formulacija općine (npr. posljedica propuštanja roka). Uređuje pravna služba – referent je ne mijenja po predmetu.');
        return $fields;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => 'Naziv',
            'Subject' => 'Naslov emaila',
            'Intro' => 'Uvodni tekst',
            'LegalNotice' => 'Pravna napomena',
            'Outro' => 'Završni tekst / potpis',
        ]);
    }

    public function canView($member = null): bool
    {
        return Permission::checkMember($member, [ApplicationRequest::PERMISSION_CONFIG, ApplicationRequest::PERMISSION_SUPERVISOR]);
    }

    public function canEdit($member = null): bool
    {
        return Permission::checkMember($member, ApplicationRequest::PERMISSION_CONFIG);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false; // keys are fixed; records come from the seed task
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
