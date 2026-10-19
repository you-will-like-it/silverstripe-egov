<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Admin;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Controller\SecureFileController;
use YouWillLikeIT\EGov\Enum\ApplicantType;
use YouWillLikeIT\EGov\Enum\DismissalReason;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Enum\SupplementReason;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\CadastralMunicipality;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;
use YouWillLikeIT\EGov\Model\FeeItem;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Service\DeadlineCalculator;

/**
 * Builds the CMS edit form of an ApplicationRequest. Kept out of the model so the
 * root DataObject stays focused on data and rules.
 *
 * Workflow inputs are prefixed with ApplicationRequest::WORKFLOW_FIELD_PREFIX and
 * consumed only by ApplicationRequestItemRequest (they are not DB fields).
 */
class RequestFieldsBuilder
{
    use Injectable;

    public function __construct(
        private readonly ApplicationRequest $record,
        private readonly ?Member $member,
    ) {
    }

    public function build(): FieldList
    {
        return $this->record->isInDB() ? $this->existing() : $this->intake();
    }

    // ------------------------------------------------------------------
    // Prijem na šalteru (new record)
    // ------------------------------------------------------------------

    private function intake(): FieldList
    {
        $r = $this->record;
        $fields = FieldList::create(TabSet::create('Root'));
        $serviceIds = MunicipalService::idsForMember($this->member, true);
        $procedures = ProcedureDefinition::get()
            ->filter(['ServiceID' => $serviceIds ?: [0], 'IsActive' => true])
            ->map('ID', 'TitleWithCode');

        $fields->addFieldsToTab('Root.Main', [
            LiteralField::create('IntakeHead', '<div class="alert alert-info"><strong>Prijem zahtjeva na šalteru.</strong> '
                . 'Nakon kreiranja predmet dobija kod za praćenje statusa. Odštampajte stranci zahtjev za potpis i potvrdu o prijemu. '
                . 'Podatke specifične za proceduru i skenirane priloge unosite nakon kreiranja.</div>'),
            DropdownField::create('ProcedureID', $r->fieldLabel('Procedure'), $procedures)->setEmptyString('— odaberite proceduru —'),
            TextField::create('ProtocolNumber', $r->fieldLabel('ProtocolNumber'))->setDescription('Obavezno za prijem na šalteru.'),
            DateField::create('ProtocolDate', $r->fieldLabel('ProtocolDate')),
            HeaderField::create('ApplicantHead', 'Podnosilac', 3),
            ...$this->applicantFields(),
            HeaderField::create('LocationHead', 'Lokacija (ako je potrebna)', 3),
            ...$this->locationFields(),
            TextareaField::create('InternalNote', $r->fieldLabel('InternalNote'))->setRows(3),
        ]);
        $fields->fieldByName('Root.Main')?->setTitle('Prijem na šalteru');
        return $fields;
    }

    // ------------------------------------------------------------------
    // Existing record
    // ------------------------------------------------------------------

    private function existing(): FieldList
    {
        $r = $this->record;
        $fields = FieldList::create(TabSet::create('Root'));

        $fields->addFieldsToTab('Root.Main', [
            LiteralField::create('EgovHeader', $this->header()),
            TextField::create('ProtocolNumber', $r->fieldLabel('ProtocolNumber'))
                ->setDescription('Unosi se ručno iz protokolarne knjige.'),
            DateField::create('ProtocolDate', $r->fieldLabel('ProtocolDate')),
            ReadonlyField::create('StatusRO', $r->fieldLabel('Status'), $r->getStatusLabel()),
            ReadonlyField::create('TrackingCodeRO', $r->fieldLabel('TrackingCode'), $r->TrackingCode),
            ReadonlyField::create('ProcedureRO', $r->fieldLabel('Procedure'), $r->Procedure()->getTitleWithCode()),
            ReadonlyField::create('ServiceRO', $r->fieldLabel('Service'), (string) $r->Service()->Title),
            ReadonlyField::create('ChannelRO', $r->fieldLabel('IntakeChannel'), $r->isCounterIntake()
                ? 'Šalter' . ($r->IntakeBy()->exists() ? ' – ' . $r->IntakeBy()->getName() : '')
                : 'Portal (email potvrđen ' . $r->formatDateTime((string) $r->EmailConfirmedAt) . ')'),
            ReadonlyField::create('SubmittedAtRO', $r->fieldLabel('SubmittedAt'), $r->formatDateTime((string) $r->SubmittedAt)),
            ReadonlyField::create('DueRO', $r->fieldLabel('DecisionDueDate'), $r->formatDate((string) $r->DecisionDueDate)),
            TextareaField::create('InternalNote', $r->fieldLabel('InternalNote'))->setRows(3),
        ]);
        $fields->fieldByName('Root.Main')?->setTitle('Predmet');

        $fields->addFieldsToTab('Root.Workflow', $this->workflowFields());
        $fields->fieldByName('Root.Workflow')?->setTitle('Radnje');

        $fields->addFieldsToTab('Root.Applicant', $this->applicantFields());
        $fields->fieldByName('Root.Applicant')?->setTitle('Podnosilac');

        $fields->addFieldsToTab('Root.Location', $this->locationFields());
        $fields->fieldByName('Root.Location')?->setTitle('Lokacija');

        $fields->addFieldsToTab('Root.Payload', $this->payloadFields());
        $fields->fieldByName('Root.Payload')?->setTitle('Podaci obrasca');

        $fields->addFieldsToTab('Root.Attachments', $this->attachmentFields());
        $fields->fieldByName('Root.Attachments')?->setTitle(sprintf('Prilozi (%d)', $r->Attachments()->count()));

        if ($r->SupplementItems()->exists()) {
            $fields->addFieldsToTab('Root.Supplements', [
                ReadonlyField::create('SupplementTextRO', $r->fieldLabel('SupplementRequestText'), $r->SupplementRequestText),
                GridField::create('SupplementItems', 'Tražena dopuna', $r->SupplementItems(), GridFieldConfig_RecordViewer::create()),
            ]);
            $fields->fieldByName('Root.Supplements')?->setTitle('Dopune');
        }

        $fields->addFieldsToTab('Root.AiReport', [LiteralField::create('AiReport', $this->aiReport())]);
        $fields->fieldByName('Root.AiReport')?->setTitle('Provjera priloga ' . $r->getAiLight()->icon());

        $fields->addFieldsToTab('Root.History', [
            GridField::create('StatusChanges', 'Historija statusa', $r->StatusChanges(), GridFieldConfig_RecordViewer::create()),
        ]);
        $fields->fieldByName('Root.History')?->setTitle('Historija');

        return $fields;
    }

    /**
     * @return list<FormField>
     */
    private function workflowFields(): array
    {
        $r = $this->record;
        $p = ApplicationRequest::WORKFLOW_FIELD_PREFIX;
        $status = $r->getStatusEnum();

        if (!$r->canDecide($this->member)) {
            return [LiteralField::create($p . 'Info', '<p class="alert alert-secondary">' . htmlspecialchars($this->stateHint(), ENT_QUOTES) . '</p>')];
        }

        $targets = [];
        foreach ($status->allowedTransitions() as $target) {
            $targets[$target->value] = $target->label();
        }

        $fields = [
            HeaderField::create($p . 'Head', 'Odluka / promjena statusa', 3),
            DropdownField::create($p . 'NewStatus', 'Novi status', $targets)->setEmptyString('— odaberite —'),
            TextareaField::create($p . 'Note', 'Interna napomena uz promjenu (ne šalje se stranci)')->setRows(2),
            HeaderField::create($p . 'SuppHead', 'Poziv za dopunu – samo dokumenti iz kataloga procedure', 4),
            LiteralField::create($p . 'SuppHint', '<p class="form__field-description">Za svaki dokument koji treba dopuniti odaberite razlog. '
                . 'Ako dokument nije priložen, jedini mogući razlog je „Nije priložen“. Tekst stranci se sastavlja automatski iz šablona.</p>'),
        ];

        $suggestion = $r->getSupplementSuggestion();
        $uploadedDocIds = array_map('intval', $r->evidenceAttachments()->sort(null)->columnUnique('RequiredDocumentID'));
        foreach ($r->Procedure()->citizenDocuments() as $doc) {
            /** @var RequiredDocument $doc */
            $has = in_array((int) $doc->ID, $uploadedDocIds, true);
            $options = SupplementReason::optionsFor($has, (bool) $doc->IsPaymentProof);
            $suggested = $suggestion[(int) $doc->ID] ?? null;
            $fields[] = DropdownField::create(
                $p . 'Doc_' . $doc->ID,
                $doc->Title . ($has ? '' : ' (nije priložen)'),
                $options,
                ($suggested !== null && array_key_exists($suggested, $options)) ? $suggested : null
            )->setEmptyString('— u redu —')
                ->setDescription($suggested !== null ? 'Prijedlog automatske provjere priloga.' : '');
        }

        $assessable = $r->getAssessableFees();
        if ($assessable !== []) {
            $fields[] = HeaderField::create($p . 'FeeHead', 'Utvrđeni iznos takse (za „Čeka uplatu“ i dopunu dokaza o uplati)', 4);
            $assessed = $r->getAssessedFees();
            foreach ($assessable as $fee) {
                [$min, $max] = $fee->expectedRange($r->getFeeUnitCount());
                $fields[] = NumericField::create($p . 'Fee_' . $fee->ID, $fee->Title . ' (KM)', $assessed[(int) $fee->ID] ?? null)
                    ->setScale(2)
                    ->setDescription($max > 0.0
                        ? sprintf('Raspon prema tarifi: %s – %s. Iznos se upisuje u poziv za uplatu.', FeeItem::km($min), FeeItem::km($max))
                        : 'Iznos nije definisan u katalogu – utvrdite ga prema tarifi.');
            }
        }

        $fields[] = NumericField::create(
            $p . 'SupplementDays',
            'Rok za dopunu (dana)',
            DeadlineCalculator::singleton()->supplementDaysFor((string) $r->RegistryCode)
        );
        $fields[] = HeaderField::create($p . 'DisHead', 'Odbacivanje (status „Odbačen“)', 4);
        $fields[] = DropdownField::create($p . 'DismissalReason', 'Razlog odbacivanja', DismissalReason::options())
            ->setEmptyString('— odaberite —');
        $fields[] = HeaderField::create($p . 'RejHead', 'Odbijanje (status „Odbijen“)', 4);
        $fields[] = TextareaField::create($p . 'RejectionReason', 'Interna napomena uz odbijanje (razlozi su u rješenju)')->setRows(2);

        return $fields;
    }

    private function stateHint(): string
    {
        $r = $this->record;
        $status = $r->getStatusEnum();
        return match (true) {
            $status === RequestStatus::Submitted => 'Predmet čeka obradu. Kliknite „Pokreni obradu“ – predmet se tada zaključava na Vas.',
            $status === RequestStatus::InProcess && $r->AssignedToID && !$r->isProcessor($this->member)
                => sprintf('Predmet obrađuje %s (od %s). Dostupan je samo za pregled.', $r->AssignedTo()->getName(), $r->formatDateTime((string) $r->ProcessingStartedAt)),
            $status === RequestStatus::InProcess => 'Predmet je otključan. Kliknite „Pokreni obradu“ da ga preuzmete.',
            $status->isSupplementRound() => sprintf('Čeka se dopuna stranke do %s. Predmet je otključan.', $r->formatDate((string) $r->SupplementDueDate)),
            $status === RequestStatus::ReadyForPickup => 'Akt je spreman. Kod preuzimanja kliknite „Evidentiraj preuzimanje“.',
            default => 'Predmet je u završnom statusu.',
        };
    }

    /**
     * @return list<FormField>
     */
    private function applicantFields(): array
    {
        $r = $this->record;
        return [
            OptionsetField::create('ApplicantType', $r->fieldLabel('ApplicantType'), ApplicantType::options()),
            TextField::create('ApplicantFullName', $r->fieldLabel('ApplicantFullName')),
            TextField::create('ApplicantParentName', $r->fieldLabel('ApplicantParentName')),
            TextField::create('ApplicantIdentificationNumber', $r->fieldLabel('ApplicantIdentificationNumber'))->setMaxLength(13),
            TextField::create('ApplicantAddress', $r->fieldLabel('ApplicantAddress')),
            TextField::create('ApplicantPostalCode', $r->fieldLabel('ApplicantPostalCode')),
            TextField::create('ApplicantCity', $r->fieldLabel('ApplicantCity')),
            TextField::create('ApplicantPhone', $r->fieldLabel('ApplicantPhone')),
            EmailField::create('ApplicantEmail', $r->fieldLabel('ApplicantEmail'))
                ->setDescription($r->isCounterIntake() || !$r->isInDB() ? 'Opcionalno. Bez emaila stranka ne dobija obavještenja – status provjerava kodom predmeta i JMBG-om.' : ''),
            TextField::create('RepresentativeName', $r->fieldLabel('RepresentativeName')),
        ];
    }

    /**
     * @return list<FormField>
     */
    private function locationFields(): array
    {
        $r = $this->record;
        $ko = CadastralMunicipality::get()->filter('IsActive', true);
        return [
            TextField::create('LocationPlace', $r->fieldLabel('LocationPlace')),
            TextField::create('LocationStreet', $r->fieldLabel('LocationStreet')),
            TextField::create('CadastralParcelNew', $r->fieldLabel('CadastralParcelNew')),
            DropdownField::create('CadastralMunicipalityNewID', $r->fieldLabel('CadastralMunicipalityNew'), $ko->filter('Survey', 'New')->map('ID', 'Title'))->setEmptyString('—'),
            TextField::create('CadastralParcelOld', $r->fieldLabel('CadastralParcelOld')),
            DropdownField::create('CadastralMunicipalityOldID', $r->fieldLabel('CadastralMunicipalityOld'), $ko->filter('Survey', 'Old')->map('ID', 'Title'))->setEmptyString('—'),
        ];
    }

    /**
     * Counter requests: editable procedure fields. Portal requests: readonly (citizen data).
     *
     * @return list<FormField>
     */
    private function payloadFields(): array
    {
        $r = $this->record;
        if ($r->procedureFieldsEditable($this->member)) {
            $out = [HeaderField::create('PayloadHead', 'Podaci iz zahtjeva (unos sa šaltera)', 3)];
            foreach ($r->getProcedureFormFields() as $field) {
                $out[] = $field;
            }
            return $out;
        }
        $out = [HeaderField::create('PayloadHead', 'Podaci iz zahtjeva', 3)];
        foreach ($r->getProcedureSummary() as $i => $row) {
            $out[] = ReadonlyField::create('PayloadRO_' . $i, (string) $row->Label, (string) $row->Value);
        }
        return $out;
    }

    /**
     * @return list<FormField>
     */
    private function attachmentFields(): array
    {
        $r = $this->record;
        $config = GridFieldConfig_RecordViewer::create();
        $config->getComponentByType(GridFieldDataColumns::class)?->setDisplayFields([
            'LightBadge' => 'Provjera',
            'DocumentLabel' => 'Vrsta dokumenta',
            'KindLabel' => 'Izvor',
            'OriginalFilename' => 'Datoteka',
            'Created.Nice' => 'Priložen',
            'DownloadLink' => 'Preuzimanje',
        ]);
        $out = [];
        if ($r->canEdit($this->member)) {
            $out[] = LiteralField::create('StaffUploadLink', sprintf(
                '<p><a class="btn btn-outline-primary" href="%s" target="_blank" rel="noopener">'
                . '<span class="font-icon-upload" aria-hidden="true"></span> Dodaj skenirani prilog / izdati akt</a> '
                . '<span class="form__field-description">Otvara se u novoj kartici; nakon uploada osvježite predmet.</span></p>',
                htmlspecialchars(SecureFileController::uploadLink($r), ENT_QUOTES)
            ));
        }
        $out[] = GridField::create('Attachments', 'Prilozi', $r->Attachments(), $config);
        return $out;
    }

    private function header(): string
    {
        $r = $this->record;
        $html = sprintf(
            '<div class="egov-cms-header"><strong>%s</strong> %s %s</div>',
            htmlspecialchars($r->Procedure()->getTitleWithCode(), ENT_QUOTES),
            $r->getStatusBadge()->forTemplate(),
            $r->getDeadlineBadge()->forTemplate()
        );
        $missing = $r->getMissingMandatoryDocuments();
        if ($missing !== []) {
            $titles = array_map(static fn (RequiredDocument $d): string => htmlspecialchars((string) $d->Title, ENT_QUOTES), $missing);
            $html .= '<p class="alert alert-warning">Nedostaju obavezni prilozi: ' . implode(', ', $titles) . '</p>';
        }
        return $html;
    }

    private function aiReport(): string
    {
        $r = $this->record;
        $light = $r->getAiLight();
        $suggested = [];
        foreach ($r->getSupplementSuggestion() as $docId => $reason) {
            $doc = RequiredDocument::get()->byID($docId);
            if ($doc) {
                $suggested[] = ['Title' => $doc->Title, 'Reason' => SupplementReason::tryFrom($reason)?->label() ?? $reason];
            }
        }
        return (string) $r->customise([
            'Attachments' => $r->evidenceAttachments(),
            'LightIcon' => $light->icon(),
            'LightLabel' => $light->label(),
            'MissingDocuments' => ArrayList::create($r->getMissingMandatoryDocuments()),
            'SuggestedItems' => ArrayList::create($suggested),
            'CheckedAt' => $r->formatDateTime((string) $r->AiCheckedAt),
        ])->renderWith('YouWillLikeIT\\EGov\\Admin\\VerificationReport');
    }
}
