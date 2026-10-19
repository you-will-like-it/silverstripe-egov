<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use JsonException;
use LogicException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;
use YouWillLikeIT\EGov\Admin\RequestFieldsBuilder;
use YouWillLikeIT\EGov\Enum\ApplicantType;
use YouWillLikeIT\EGov\Enum\DismissalReason;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Enum\VerificationLight;
use YouWillLikeIT\EGov\Procedure\PayloadFieldFactory;
use YouWillLikeIT\EGov\Procedure\ProcedureRegistry;
use YouWillLikeIT\EGov\Service\DeadlineCalculator;
use YouWillLikeIT\EGov\Service\IdentifierGenerator;
use YouWillLikeIT\EGov\Validation\IdentificationNumberValidator;

/**
 * Root of every administrative request (predmet).
 *
 * Holds everything identical for 100 % of procedures: case metadata, applicant,
 * basic location, flexible JSON payload, portal identifiers, lock (processor)
 * and state machine bookkeeping. Procedure specific behaviour is added by
 * subclassing (see UrbanConsentRequest, CadastralPlanCopyRequest); simple
 * procedures use this class directly with a YAML FormPayload schema.
 *
 * Extension points for subclasses:
 *  - getProcedureFormFields() / getProcedureRequiredFields() / saveProcedureFormData()
 *  - getProcedureSummary()
 *  - getFeeVariantKey() / getFeeUnitCount()
 *  - getSubjectLine() / getPdfIntroText()
 *
 * @property string $RegistryCode
 * @property string $ServiceClass
 * @property string $ProtocolNumber
 * @property string|null $ProtocolDate
 * @property string $Status
 * @property string $IntakeChannel  Portal|Counter
 * @property string $TrackingCode
 * @property string $PublicHash
 * @property string|null $ConfirmationToken
 * @property string|null $ConfirmationExpiresAt
 * @property string|null $EmailConfirmedAt
 * @property string|null $SubmittedAt
 * @property string|null $DeadlineStartedAt
 * @property string|null $DecisionDueDate
 * @property string|null $SupplementDueDate
 * @property string|null $SupplementRequestText
 * @property int $SupplementRound
 * @property string|null $ProcessingStartedAt
 * @property string|null $CompletedAt
 * @property string|null $PickedUpAt
 * @property string|null $RejectionReason
 * @property string|null $DismissalReason
 * @property string $ApplicantType
 * @property string $ApplicantFullName
 * @property string $ApplicantParentName
 * @property string $ApplicantIdentificationNumber
 * @property string $ApplicantAddress
 * @property string $ApplicantPostalCode
 * @property string $ApplicantCity
 * @property string $ApplicantPhone
 * @property string $ApplicantEmail
 * @property string $RepresentativeName
 * @property string $LocationPlace
 * @property string $LocationStreet
 * @property string $CadastralParcelNew
 * @property string $CadastralParcelOld
 * @property string|null $FormPayload
 * @property string $AiOverallLight
 * @property string|null $AiSupplementSuggestion  JSON {RequiredDocumentID: SupplementReason}
 * @property string|null $AiCheckedAt
 * @property string|null $PrivacyConsentAt
 * @property string|null $InternalNote
 * @property int $ProcedureID
 * @property int $ServiceID
 * @property int $CadastralMunicipalityNewID
 * @property int $CadastralMunicipalityOldID
 * @property int $AssignedToID
 * @property int $IntakeByID
 * @method ProcedureDefinition Procedure()
 * @method MunicipalService Service()
 * @method CadastralMunicipality CadastralMunicipalityNew()
 * @method CadastralMunicipality CadastralMunicipalityOld()
 * @method Member AssignedTo()
 * @method Member IntakeBy()
 * @method HasManyList<ApplicationAttachment> Attachments()
 * @method HasManyList<ApplicationStatusChange> StatusChanges()
 * @method HasManyList<SupplementItem> SupplementItems()
 */
class ApplicationRequest extends DataObject implements PermissionProvider
{
    public const PERMISSION_REFERENT = 'EGOV_REFERENT';
    public const PERMISSION_SUPERVISOR = 'EGOV_SUPERVISOR';
    public const PERMISSION_CONFIG = 'EGOV_CONFIG';
    public const PERMISSION_REPORTS = 'EGOV_REPORTS';
    public const PERMISSION_AUDIT = 'EGOV_AUDIT';

    public const CHANNEL_PORTAL = 'Portal';
    public const CHANNEL_COUNTER = 'Counter';

    /** Prefix for non-persisted workflow inputs in the CMS edit form. */
    public const WORKFLOW_FIELD_PREFIX = 'EgovWf_';

    private static string $table_name = 'EGov_ApplicationRequest';

    private static string $singular_name = 'Zahtjev (opći)';

    private static string $plural_name = 'Zahtjevi';

    private static array $db = [
        'RegistryCode' => 'Varchar(16)',
        'ServiceClass' => 'Varchar(32)',
        'ProtocolNumber' => 'Varchar(64)',
        'ProtocolDate' => 'Date',
        'Status' => "Enum('Draft,Submitted,Incomplete,AwaitingPayment,InProcess,ReadyForPickup,Completed,Rejected,Dismissed','Draft')",
        'IntakeChannel' => "Enum('Portal,Counter','Portal')",
        'TrackingCode' => 'Varchar(16)',
        'PublicHash' => 'Varchar(64)',
        'ConfirmationToken' => 'Varchar(64)',
        'ConfirmationExpiresAt' => 'Datetime',
        'EmailConfirmedAt' => 'Datetime',
        'SubmittedAt' => 'Datetime',
        'DeadlineStartedAt' => 'Datetime',
        'DecisionDueDate' => 'Date',
        'SupplementDueDate' => 'Date',
        'SupplementRequestText' => 'Text',
        'SupplementRound' => 'Int',
        'ProcessingStartedAt' => 'Datetime',
        'CompletedAt' => 'Datetime',
        'PickedUpAt' => 'Datetime',
        'RejectionReason' => 'Text',
        'DismissalReason' => "Enum('SupplementNotProvided,NotCompetent,Withdrawn,Untimely','SupplementNotProvided')",
        'ApplicantType' => "Enum('Individual,Company','Individual')",
        'ApplicantFullName' => 'Varchar(255)',
        'ApplicantParentName' => 'Varchar(100)',
        'ApplicantIdentificationNumber' => 'Varchar(13)',
        'ApplicantAddress' => 'Varchar(255)',
        'ApplicantPostalCode' => 'Varchar(10)',
        'ApplicantCity' => 'Varchar(100)',
        'ApplicantPhone' => 'Varchar(50)',
        'ApplicantEmail' => 'Varchar(255)',
        'RepresentativeName' => 'Varchar(255)',
        'LocationPlace' => 'Varchar(255)',
        'LocationStreet' => 'Varchar(255)',
        'CadastralParcelNew' => 'Varchar(255)',
        'CadastralParcelOld' => 'Varchar(255)',
        'FormPayload' => 'Text',
        'AiOverallLight' => "Enum('Pending,Green,Yellow,Red,Error','Pending')",
        'AiSupplementSuggestion' => 'Text',
        'AiCheckedAt' => 'Datetime',
        'PrivacyConsentAt' => 'Datetime',
        'InternalNote' => 'Text',
    ];

    private static array $has_one = [
        'Procedure' => ProcedureDefinition::class,
        'Service' => MunicipalService::class,
        'CadastralMunicipalityNew' => CadastralMunicipality::class,
        'CadastralMunicipalityOld' => CadastralMunicipality::class,
        'AssignedTo' => Member::class,
        'IntakeBy' => Member::class,
    ];

    private static array $has_many = [
        'Attachments' => ApplicationAttachment::class . '.Request',
        'StatusChanges' => ApplicationStatusChange::class . '.Request',
        'SupplementItems' => SupplementItem::class . '.Request',
    ];

    /**
     * AuditLogEntry is deliberately NOT cascaded – the access trail must outlive the case.
     */
    private static array $cascade_deletes = ['Attachments', 'StatusChanges', 'SupplementItems'];

    private static array $indexes = [
        'TrackingCode' => ['type' => 'unique', 'columns' => ['TrackingCode']],
        'PublicHash' => ['type' => 'unique', 'columns' => ['PublicHash']],
        'ConfirmationToken' => true,
        'Status' => true,
        'ProtocolNumber' => true,
        'DecisionDueDate' => true,
        'SubmittedAt' => true,
        'StatusService' => ['type' => 'index', 'columns' => ['Status', 'ServiceID']],
    ];

    private static string $default_sort = '"SubmittedAt" DESC, "Created" DESC';

    private static array $defaults = [
        'Status' => 'Draft',
        'IntakeChannel' => 'Portal',
        'ApplicantType' => 'Individual',
        'AiOverallLight' => 'Pending',
    ];

    private static array $casting = [
        'StatusBadge' => 'HTMLFragment',
        'DeadlineBadge' => 'HTMLFragment',
        'AiBadge' => 'HTMLFragment',
        'StatusLabel' => 'Varchar',
        'ApplicantIdentificationMasked' => 'Varchar',
    ];

    private static array $summary_fields = [
        'TrackingCode' => 'Kod',
        'ProtocolNumber' => 'Broj protokola',
        'RegistryCode' => 'Šifra',
        'Procedure.Title' => 'Procedura',
        'ApplicantFullName' => 'Podnosilac',
        'SubmittedAt.Nice' => 'Podnesen',
        'StatusBadge' => 'Status',
        'DeadlineBadge' => 'Rok',
        'AiBadge' => 'AI',
    ];

    private static array $searchable_fields = [
        'TrackingCode' => ['title' => 'Kod predmeta', 'filter' => 'PartialMatchFilter'],
        'ProtocolNumber' => ['title' => 'Broj protokola', 'filter' => 'PartialMatchFilter'],
        'ApplicantFullName' => ['title' => 'Podnosilac', 'filter' => 'PartialMatchFilter'],
        'ApplicantIdentificationNumber' => ['title' => 'JMBG / ID', 'filter' => 'ExactMatchFilter'],
        'Status' => ['title' => 'Status', 'filter' => 'ExactMatchFilter'],
        'ServiceID' => ['title' => 'Služba', 'field' => \SilverStripe\Forms\TextField::class, 'filter' => 'ExactMatchFilter'],
        'ProcedureID' => ['title' => 'Procedura', 'field' => \SilverStripe\Forms\TextField::class, 'filter' => 'ExactMatchFilter'],
        'AssignedToID' => ['title' => 'Obrađuje', 'field' => \SilverStripe\Forms\TextField::class, 'filter' => 'ExactMatchFilter'],
        'IntakeChannel' => ['title' => 'Kanal', 'filter' => 'ExactMatchFilter'],
        'AiOverallLight' => ['title' => 'AI semafor', 'filter' => 'ExactMatchFilter'],
    ];

    /**
     * Optional regex for manually entered protocol numbers, e.g. '#^\d{2}-\d{2}-\d+/\d{2}$#'.
     * Empty = uniqueness check only.
     *
     * @config
     */
    private static string $protocol_number_pattern = '';

    /** Set only by ApplicationWorkflow – guards Status against direct writes. */
    private bool $statusWriteAuthorised = false;

    /** @var array<string, mixed>|null */
    private ?array $payloadCache = null;

    // =====================================================================
    // Permissions & lock
    // =====================================================================

    public function providePermissions(): array
    {
        $category = 'e-Uprava';
        return [
            self::PERMISSION_REFERENT => ['name' => 'Referent: obrada zahtjeva vlastitih službi i prijem na šalteru', 'category' => $category],
            self::PERMISSION_SUPERVISOR => ['name' => 'Nadzor: pregled svih zahtjeva i otključavanje (bez donošenja odluka)', 'category' => $category],
            self::PERMISSION_REPORTS => ['name' => 'Izvještaji (PDF)', 'category' => $category],
            self::PERMISSION_AUDIT => ['name' => 'Pregled revizijskog traga pristupa', 'category' => $category],
            self::PERMISSION_CONFIG => ['name' => 'Administracija procedura, taksi, emailova i parametara općine', 'category' => $category],
        ];
    }

    private function member(?Member $member): ?Member
    {
        return $member ?? Security::getCurrentUser();
    }

    public function isSupervisor(?Member $member = null): bool
    {
        $member = $this->member($member);
        return $member !== null && Permission::checkMember($member, ['ADMIN', self::PERMISSION_SUPERVISOR]);
    }

    /**
     * Member is a referent of this request's service (group membership – supervisors excluded).
     */
    public function isServiceReferent(?Member $member = null): bool
    {
        $member = $this->member($member);
        return $member !== null
            && Permission::checkMember($member, self::PERMISSION_REFERENT)
            && in_array((int) $this->ServiceID, MunicipalService::idsForMember($member, true), true);
    }

    public function isProcessor(?Member $member = null): bool
    {
        $member = $this->member($member);
        return $member !== null && (int) $this->AssignedToID > 0 && (int) $this->AssignedToID === (int) $member->ID;
    }

    public function isLocked(): bool
    {
        return $this->getStatusEnum() === RequestStatus::InProcess && (int) $this->AssignedToID > 0;
    }

    public function canStartProcessing(?Member $member = null): bool
    {
        $status = $this->getStatusEnum();
        return $this->isServiceReferent($member)
            && ($status === RequestStatus::Submitted || ($status === RequestStatus::InProcess && !$this->AssignedToID));
    }

    public function canDecide(?Member $member = null): bool
    {
        return $this->getStatusEnum() === RequestStatus::InProcess
            && $this->isProcessor($member)
            && $this->isServiceReferent($member);
    }

    public function canRelease(?Member $member = null): bool
    {
        return $this->getStatusEnum() === RequestStatus::InProcess && $this->isProcessor($member);
    }

    public function canUnlock(?Member $member = null): bool
    {
        return $this->isLocked() && !$this->isProcessor($member) && $this->isSupervisor($member);
    }

    public function canRegisterPickup(?Member $member = null): bool
    {
        return $this->getStatusEnum() === RequestStatus::ReadyForPickup && $this->isServiceReferent($member);
    }

    public function canDismissExpiredSupplement(?Member $member = null): bool
    {
        if (!$this->getStatusEnum()->isSupplementRound() || !$this->SupplementDueDate || !$this->isServiceReferent($member)) {
            return false;
        }
        return DeadlineCalculator::singleton()->daysUntil((string) $this->SupplementDueDate) < 0;
    }

    /**
     * Counter intake data may be corrected by the intake referent until processing starts,
     * and by the processor while in process.
     */
    public function procedureFieldsEditable(?Member $member = null): bool
    {
        return $this->IntakeChannel === self::CHANNEL_COUNTER && $this->canEdit($member);
    }

    public function canView($member = null): bool
    {
        $member = $this->member($member);
        if (!$member) {
            return false;
        }
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }
        if (!$this->isInDB()) {
            return $this->canCreate($member);
        }
        return $this->isSupervisor($member) || $this->isServiceReferent($member);
    }

    public function canEdit($member = null): bool
    {
        $member = $this->member($member);
        if (!$this->isInDB()) {
            return $this->canCreate($member);
        }
        return match ($this->getStatusEnum()) {
            RequestStatus::Draft => Permission::checkMember($member, 'ADMIN'),
            RequestStatus::InProcess => $this->canDecide($member),
            RequestStatus::Submitted => $this->IntakeChannel === self::CHANNEL_COUNTER
                && (int) $this->IntakeByID === (int) $member?->ID
                && $this->isServiceReferent($member),
            RequestStatus::ReadyForPickup, RequestStatus::Completed => $this->isServiceReferent($member),
            default => false,
        };
    }

    /**
     * Staff can create requests only through counter intake ("prijem na šalteru").
     */
    public function canCreate($member = null, $context = []): bool
    {
        $member = $this->member($member);
        return $member !== null
            && Permission::checkMember($member, self::PERMISSION_REFERENT)
            && MunicipalService::idsForMember($member, true) !== [];
    }

    public function canDelete($member = null): bool
    {
        return Permission::checkMember($member, 'ADMIN') && $this->getStatusEnum() === RequestStatus::Draft;
    }

    // =====================================================================
    // Status
    // =====================================================================

    public function getStatusEnum(): RequestStatus
    {
        return RequestStatus::tryFrom((string) $this->Status) ?? RequestStatus::Draft;
    }

    public function getStatusLabel(): string
    {
        $status = $this->getStatusEnum();
        if ($status === RequestStatus::InProcess && $this->AssignedToID) {
            return $status->label() . ' – ' . $this->AssignedTo()->getName();
        }
        if ($status === RequestStatus::Dismissed && $this->DismissalReason) {
            return $status->label() . ' (' . $this->getDismissalReasonEnum()->label() . ')';
        }
        return $status->label();
    }

    /** Citizen-facing label (never reveals the processor's name). */
    public function getPublicStatusLabel(): string
    {
        return $this->getStatusEnum()->label();
    }

    public function getDismissalReasonEnum(): DismissalReason
    {
        return DismissalReason::tryFrom((string) $this->DismissalReason) ?? DismissalReason::SupplementNotProvided;
    }

    public function getApplicantTypeEnum(): ApplicantType
    {
        return ApplicantType::tryFrom((string) $this->ApplicantType) ?? ApplicantType::Individual;
    }

    public function isCounterIntake(): bool
    {
        return $this->IntakeChannel === self::CHANNEL_COUNTER;
    }

    /**
     * @internal Only {@see \YouWillLikeIT\EGov\Workflow\ApplicationWorkflow} calls this.
     */
    public function applyStatusFromWorkflow(RequestStatus $status): void
    {
        $this->statusWriteAuthorised = true;
        $this->Status = $status->value;
    }

    public function getStatusBadge(): DBHTMLText
    {
        $status = $this->getStatusEnum();
        $lock = $this->isLocked() ? '<span aria-hidden="true">🔒 </span>' : '';
        return DBField::create_field('HTMLFragment', sprintf(
            '<span class="egov-badge egov-badge--%s">%s%s</span>',
            $status->tone(),
            $lock,
            htmlspecialchars($this->getStatusLabel(), ENT_QUOTES)
        ));
    }

    public function getAiLight(): VerificationLight
    {
        return VerificationLight::tryFrom((string) $this->AiOverallLight) ?? VerificationLight::Pending;
    }

    public function getAiBadge(): DBHTMLText
    {
        $light = $this->getAiLight();
        return DBField::create_field('HTMLFragment', sprintf(
            '<span title="%1$s" aria-label="%1$s">%2$s</span>',
            htmlspecialchars($light->label(), ENT_QUOTES),
            $light->icon()
        ));
    }

    public function getDaysUntilDecisionDue(): ?int
    {
        if (!$this->DecisionDueDate || $this->getStatusEnum()->isTerminal()) {
            return null;
        }
        return DeadlineCalculator::singleton()->daysUntil((string) $this->DecisionDueDate);
    }

    public function isOverdue(): bool
    {
        $days = $this->getDaysUntilDecisionDue();
        return $days !== null && $days < 0;
    }

    public function getDeadlineBadge(): DBHTMLText
    {
        $status = $this->getStatusEnum();
        if ($status->isTerminal()) {
            return DBField::create_field('HTMLFragment', '<span class="egov-deadline egov-deadline--done">—</span>');
        }
        if ($status->suspendsDeadline()) {
            $text = $status->isSupplementRound() && $this->SupplementDueDate
                ? 'Dopuna do ' . $this->formatDate((string) $this->SupplementDueDate)
                : 'Rok miruje';
            return DBField::create_field('HTMLFragment', sprintf(
                '<span class="egov-deadline egov-deadline--paused">%s</span>',
                htmlspecialchars($text, ENT_QUOTES)
            ));
        }
        $days = $this->getDaysUntilDecisionDue();
        if ($days === null) {
            return DBField::create_field('HTMLFragment', '');
        }
        $warnDays = (int) DeadlineCalculator::config()->get('warning_days');
        $tone = $days < 0 ? 'overdue' : ($days <= $warnDays ? 'warning' : 'ok');
        $label = $days < 0
            ? sprintf('Prekoračen %d d.', abs($days))
            : sprintf('%s (%d d.)', $this->formatDate((string) $this->DecisionDueDate), $days);
        return DBField::create_field('HTMLFragment', sprintf(
            '<span class="egov-deadline egov-deadline--%s">%s</span>',
            $tone,
            htmlspecialchars($label, ENT_QUOTES)
        ));
    }

    // =====================================================================
    // Identifiers & links
    // =====================================================================

    public function getPortalLink(string $action = 'predmet'): string
    {
        return Controller::join_links(ProcedureRegistry::singleton()->portalBaseLink(), $action, $this->PublicHash);
    }

    public function getAbsolutePortalLink(string $action = 'predmet'): string
    {
        return Controller::join_links(Director::absoluteBaseURL(), $this->getPortalLink($action));
    }

    public function getAbsoluteConfirmationLink(): string
    {
        return Controller::join_links(
            Director::absoluteBaseURL(),
            ProcedureRegistry::singleton()->portalBaseLink(),
            'potvrda',
            (string) $this->ConfirmationToken
        );
    }

    public function getAbsoluteStatusPageLink(): string
    {
        return Controller::join_links(Director::absoluteBaseURL(), ProcedureRegistry::singleton()->portalBaseLink(), 'status');
    }

    public function getApplicantIdentificationMasked(): string
    {
        $id = (string) $this->ApplicantIdentificationNumber;
        if (strlen($id) < 6) {
            return $id;
        }
        return substr($id, 0, 2) . str_repeat('•', strlen($id) - 5) . substr($id, -3);
    }

    public function matchesIdentification(string $candidate): bool
    {
        $candidate = (string) preg_replace('/\D+/', '', $candidate);
        return $candidate !== '' && hash_equals((string) $this->ApplicantIdentificationNumber, $candidate);
    }

    public function hasEmail(): bool
    {
        return filter_var((string) $this->ApplicantEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    // =====================================================================
    // FormPayload (JSON)
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        if ($this->payloadCache !== null) {
            return $this->payloadCache;
        }
        $raw = (string) $this->FormPayload;
        if ($raw === '') {
            return $this->payloadCache = [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = [];
        }
        return $this->payloadCache = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): static
    {
        $this->payloadCache = $payload;
        $this->FormPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this;
    }

    public function getPayloadValue(string $key, mixed $default = null): mixed
    {
        return $this->getPayload()[$key] ?? $default;
    }

    public function setPayloadValue(string $key, mixed $value): static
    {
        $payload = $this->getPayload();
        $payload[$key] = $value;
        return $this->setPayload($payload);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPayloadSchema(): array
    {
        return ProcedureRegistry::singleton()->payloadSchema((string) ($this->RegistryCode ?: $this->Procedure()->Code));
    }

    // =====================================================================
    // Procedure hooks (override in subclasses)
    // =====================================================================

    public function getProcedureFormFields(): FieldList
    {
        $fields = PayloadFieldFactory::singleton()->buildFields($this->getPayloadSchema());
        // pre-fill from stored payload (CMS editing of counter requests)
        foreach ($this->getPayload() as $key => $value) {
            $fields->dataFieldByName(PayloadFieldFactory::PREFIX . $key)?->setValue($value);
        }
        return $fields;
    }

    /**
     * @return list<string>
     */
    public function getProcedureRequiredFields(): array
    {
        return PayloadFieldFactory::singleton()->requiredNames($this->getPayloadSchema());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveProcedureFormData(array $data): void
    {
        $values = PayloadFieldFactory::singleton()->extractValues($this->getPayloadSchema(), $data);
        if ($values !== []) {
            $this->setPayload(array_merge($this->getPayload(), $values));
        }
    }

    /**
     * @return ArrayList<ArrayData>
     */
    public function getProcedureSummary(): ArrayList
    {
        $rows = ArrayList::create();
        foreach (PayloadFieldFactory::singleton()->describe($this->getPayloadSchema(), $this->getPayload()) as $label => $value) {
            $rows->push(ArrayData::create(['Label' => $label, 'Value' => $value]));
        }
        return $rows;
    }

    public function getPdfIntroText(): ?string
    {
        return null;
    }

    public function getSubjectLine(): string
    {
        $title = (string) $this->Procedure()->Title;
        return $title === '' ? 'Zahtjev' : 'Zahtjev – ' . $title;
    }

    // =====================================================================
    // Fees & documents
    // =====================================================================

    public function getFeeVariantKey(): ?string
    {
        return null;
    }

    public function getFeeUnitCount(): int
    {
        return 1;
    }

    /**
     * @return DataList<FeeItem>
     */
    public function getApplicableFees(): DataList
    {
        $variants = [''];
        $variant = $this->getFeeVariantKey();
        if ($variant !== null && $variant !== '') {
            $variants[] = $variant;
        }
        return FeeItem::get()->filter(['ProcedureID' => $this->ProcedureID, 'VariantKey' => $variants]);
    }

    /**
     * @return ArrayList<ArrayData>
     */
    public function getExpectedPayments(): ArrayList
    {
        $out = ArrayList::create();
        foreach ($this->getApplicableFees() as $fee) {
            [$min, $max] = $fee->expectedRange($this->getFeeUnitCount());
            $out->push(ArrayData::create([
                'Fee' => $fee,
                'AmountMin' => $min,
                'AmountMax' => $max,
                'AmountLabel' => $max <= 0.0
                    ? ($fee->AmountNote ?: 'prema tarifi')
                    : ($min === $max ? FeeItem::km($min) : FeeItem::km($min) . ' – ' . FeeItem::km($max)),
            ]));
        }
        return $out;
    }

    /**
     * Citizen/staff evidence attachments (issued documents excluded).
     *
     * @return DataList<ApplicationAttachment>
     */
    public function evidenceAttachments(): DataList
    {
        return $this->Attachments()->exclude('Kind', ApplicationAttachment::KIND_RESULT);
    }

    public function getResultDocument(): ?ApplicationAttachment
    {
        $doc = $this->Attachments()->filter('Kind', ApplicationAttachment::KIND_RESULT)->sort('Created', 'DESC')->first();
        return $doc instanceof ApplicationAttachment ? $doc : null;
    }

    /**
     * @return list<RequiredDocument>
     */
    public function getMissingMandatoryDocuments(): array
    {
        $uploaded = array_map('intval', $this->evidenceAttachments()->sort(null)->columnUnique('RequiredDocumentID'));
        $missing = [];
        foreach ($this->Procedure()->citizenDocuments()->filter('IsMandatory', true) as $doc) {
            if (!in_array((int) $doc->ID, $uploaded, true)) {
                $missing[] = $doc;
            }
        }
        return $missing;
    }

    /**
     * AI / rules suggestion for the supplement checklist.
     *
     * @return array<int, string> RequiredDocumentID => SupplementReason value
     */
    public function getSupplementSuggestion(): array
    {
        try {
            $data = json_decode((string) $this->AiSupplementSuggestion, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $out = [];
        foreach (is_array($data) ? $data : [] as $docId => $reason) {
            $out[(int) $docId] = (string) $reason;
        }
        return $out;
    }

    public function refreshAiAggregate(): void
    {
        $lights = [];
        foreach ($this->evidenceAttachments() as $attachment) {
            $lights[] = $attachment->getLight();
        }
        $aggregate = VerificationLight::worstOf($lights);
        if ($this->getMissingMandatoryDocuments() !== [] && $aggregate->severity() < VerificationLight::Red->severity()) {
            $aggregate = VerificationLight::Red;
        }
        $this->AiOverallLight = $aggregate->value;
    }

    // =====================================================================
    // Lifecycle & validation
    // =====================================================================

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if ($this->isInDB() && $this->isChanged('Status', DataObject::CHANGE_VALUE) && !$this->statusWriteAuthorised) {
            throw new LogicException('Status zahtjeva se mijenja isključivo kroz ApplicationWorkflow.');
        }
        $this->statusWriteAuthorised = false;

        $generator = IdentifierGenerator::singleton();
        if (!$this->PublicHash) {
            $this->PublicHash = $generator->publicHash();
        }
        if (!$this->TrackingCode) {
            $this->TrackingCode = $generator->trackingCode();
        }

        if ($this->ProcedureID) {
            $procedure = $this->Procedure();
            $this->ServiceID = (int) $procedure->ServiceID;
            if (!$this->RegistryCode) {
                $this->RegistryCode = (string) $procedure->Code;
            }
            if (!$this->ServiceClass) {
                $this->ServiceClass = (string) $procedure->ServiceClass;
            }
        }

        $this->ApplicantIdentificationNumber = (string) preg_replace('/\D+/', '', (string) $this->ApplicantIdentificationNumber);
        $this->ApplicantEmail = strtolower(trim((string) $this->ApplicantEmail));
        $this->ProtocolNumber = trim((string) $this->ProtocolNumber);
        if ($this->ProtocolNumber !== '' && !$this->ProtocolDate) {
            $this->ProtocolDate = date('Y-m-d', DBDatetime::now()->getTimestamp());
        }
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->ProcedureID) {
            $result->addFieldError('ProcedureID', 'Odaberite proceduru.');
        }

        $idError = Injector::inst()->get(IdentificationNumberValidator::class)
            ->validate((string) $this->ApplicantIdentificationNumber, $this->getApplicantTypeEnum());
        if ($idError !== null) {
            $result->addFieldError('ApplicantIdentificationNumber', $idError);
        }

        if (trim((string) $this->ApplicantFullName) === '') {
            $result->addFieldError('ApplicantFullName', 'Unesite ime i prezime / naziv podnosioca.');
        }

        // Email is mandatory for portal requests (double-opt-in), optional at the counter.
        $email = trim((string) $this->ApplicantEmail);
        if (($email !== '' || !$this->isCounterIntake()) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result->addFieldError('ApplicantEmail', 'Unesite ispravnu email adresu.');
        }

        if ($this->isCounterIntake() && trim((string) $this->ProtocolNumber) === '') {
            $result->addFieldError('ProtocolNumber', 'Za prijem na šalteru broj protokola je obavezan.');
        }

        if (trim((string) $this->ProtocolNumber) !== '') {
            $pattern = (string) static::config()->get('protocol_number_pattern');
            if ($pattern !== '' && !preg_match($pattern, trim((string) $this->ProtocolNumber))) {
                $result->addFieldError('ProtocolNumber', 'Broj protokola nije u očekivanom formatu.');
            }
            $duplicate = self::get()
                ->filter('ProtocolNumber', trim((string) $this->ProtocolNumber))
                ->exclude('ID', (int) $this->ID)
                ->exists();
            if ($duplicate) {
                $result->addFieldError('ProtocolNumber', 'Ovaj broj protokola je već dodijeljen drugom predmetu.');
            }
        }

        return $result;
    }

    // =====================================================================
    // CMS
    // =====================================================================

    public function getCMSFields(): FieldList
    {
        $fields = RequestFieldsBuilder::create($this, Security::getCurrentUser())->build();
        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'RegistryCode' => 'Šifra procedure',
            'ServiceClass' => 'Klasifikacijska oznaka',
            'ProtocolNumber' => 'Broj protokola',
            'ProtocolDate' => 'Datum protokola',
            'Status' => 'Status',
            'IntakeChannel' => 'Kanal podnošenja',
            'TrackingCode' => 'Kod predmeta',
            'SubmittedAt' => 'Podnesen',
            'DecisionDueDate' => 'Zakonski rok za rješavanje',
            'SupplementDueDate' => 'Rok za dopunu',
            'SupplementRequestText' => 'Poziv za dopunu (poslan stranci)',
            'CompletedAt' => 'Završen',
            'PickedUpAt' => 'Preuzet',
            'RejectionReason' => 'Interna napomena uz odluku',
            'DismissalReason' => 'Razlog odbacivanja',
            'ApplicantType' => 'Vrsta podnosioca',
            'ApplicantFullName' => 'Ime i prezime / naziv',
            'ApplicantParentName' => 'Ime jednog roditelja',
            'ApplicantIdentificationNumber' => 'JMBG / ID broj',
            'ApplicantAddress' => 'Adresa',
            'ApplicantPostalCode' => 'Poštanski broj',
            'ApplicantCity' => 'Mjesto',
            'ApplicantPhone' => 'Kontakt telefon',
            'ApplicantEmail' => 'Email',
            'RepresentativeName' => 'Zastupnik / punomoćnik / kontakt osoba',
            'LocationPlace' => 'Mjesto',
            'LocationStreet' => 'Ulica i broj',
            'CadastralParcelNew' => 'K.č. broj (novi premjer)',
            'CadastralParcelOld' => 'K.č. broj (stari premjer)',
            'CadastralMunicipalityNew' => 'K.o. (novi premjer)',
            'CadastralMunicipalityOld' => 'K.o. (stari premjer)',
            'Procedure' => 'Procedura',
            'Service' => 'Služba',
            'AssignedTo' => 'Obrađuje',
            'IntakeBy' => 'Zaprimio na šalteru',
            'InternalNote' => 'Interna bilješka (vidljiva samo službi)',
            'AiOverallLight' => 'AI semafor',
        ]);
    }

    // =====================================================================
    // Formatting helpers (dd.mm.yyyy.)
    // =====================================================================

    public function formatDate(string $value): string
    {
        if ($value === '') {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date('d.m.Y.', $ts);
    }

    public function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date('d.m.Y. H:i', $ts);
    }

    public function getSubmittedAtFormatted(): string
    {
        return $this->formatDate((string) $this->SubmittedAt);
    }

    public function getDecisionDueFormatted(): string
    {
        return $this->formatDate((string) $this->DecisionDueDate);
    }

    public function getSupplementDueFormatted(): string
    {
        return $this->formatDate((string) $this->SupplementDueDate);
    }

    public function getCadastralSummary(): string
    {
        $parts = [];
        if ($this->CadastralParcelNew) {
            $parts[] = sprintf('k.č. %s, k.o. %s (novi premjer)', $this->CadastralParcelNew, $this->CadastralMunicipalityNew()->Title ?: '—');
        }
        if ($this->CadastralParcelOld) {
            $parts[] = sprintf('k.č. %s, k.o. %s (stari premjer)', $this->CadastralParcelOld, $this->CadastralMunicipalityOld()->Title ?: '—');
        }
        return implode('; ', $parts);
    }
}
