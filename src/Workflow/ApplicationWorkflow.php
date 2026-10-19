<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Workflow;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\DismissalReason;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\ApplicationStatusChange;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Model\SupplementItem;
use YouWillLikeIT\EGov\Service\AuditLogger;
use YouWillLikeIT\EGov\Service\DeadlineCalculator;
use YouWillLikeIT\EGov\Service\IdentifierGenerator;
use YouWillLikeIT\EGov\Service\NotificationService;
use YouWillLikeIT\EGov\Verification\SupplementNoticeBuilder;

/**
 * State machine – the only code path allowed to change ApplicationRequest.Status.
 *
 *   Portal: Draft ─(email)→ Submitted        Šalter: (prijem) → Submitted
 *   Submitted ─(Pokreni obradu: zaključava na referenta)→ InProcess
 *   InProcess ─(samo referent koji obrađuje)→ Incomplete | AwaitingPayment | ReadyForPickup | Completed | Rejected | Dismissed
 *   Incomplete/AwaitingPayment ─(dopuna stranke)→ Submitted   ─(rok istekao)→ Dismissed
 *   ReadyForPickup ─(preuzimanje, bilo koji referent službe)→ Completed
 *
 * Supervisors can unlock a case but never decide it.
 * ZUP deadline starts when the request is complete, is suspended during a supplement
 * round and restarts after the supplement.
 */
class ApplicationWorkflow
{
    use Injectable;
    use Configurable;

    /**
     * Target statuses for which a protocol number must exist.
     *
     * @config
     * @var list<string>
     */
    private static array $require_protocol_for = ['Incomplete', 'AwaitingPayment', 'ReadyForPickup', 'Completed', 'Rejected', 'Dismissed'];

    /**
     * "Spreman za preuzimanje" only with an uploaded copy of the issued act.
     * Default off – decide with the referents.
     *
     * @config
     */
    private static bool $require_result_document_for_pickup = false;

    /** @config */
    private static int $confirmation_ttl_hours = 48;

    public function __construct(
        private ?NotificationService $notifications = null,
        private ?DeadlineCalculator $deadlines = null,
        private ?AuditLogger $audit = null,
        private ?SupplementNoticeBuilder $notices = null,
    ) {
        $this->notifications ??= NotificationService::singleton();
        $this->deadlines ??= DeadlineCalculator::singleton();
        $this->audit ??= AuditLogger::singleton();
        $this->notices ??= SupplementNoticeBuilder::singleton();
    }

    // ------------------------------------------------------------------
    // Portal (double opt-in)
    // ------------------------------------------------------------------

    public function requestConfirmation(ApplicationRequest $request): bool
    {
        if ($request->getStatusEnum() !== RequestStatus::Draft) {
            throw new TransitionException('Zahtjev je već podnesen.');
        }
        $missing = $request->getMissingMandatoryDocuments();
        if ($missing !== []) {
            throw new TransitionException(sprintf(
                'Nedostaju obavezni prilozi: %s.',
                implode(', ', array_map(static fn ($d): string => (string) $d->Title, $missing))
            ));
        }
        $ttl = max(1, (int) static::config()->get('confirmation_ttl_hours'));
        $request->ConfirmationToken = IdentifierGenerator::singleton()->token();
        $request->ConfirmationExpiresAt = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + $ttl * 3600);
        $request->write();
        return $this->notifications->sendConfirmationRequest($request);
    }

    public function confirmSubmission(ApplicationRequest $request): void
    {
        if ($request->getStatusEnum() !== RequestStatus::Draft) {
            return; // idempotent
        }
        $expires = strtotime((string) $request->ConfirmationExpiresAt);
        if ($expires === false || $expires < DBDatetime::now()->getTimestamp()) {
            throw new TransitionException('Link za potvrdu je istekao. Zatražite novi link na stranici predmeta.');
        }
        $request->EmailConfirmedAt = $this->now();
        $request->ConfirmationToken = null;
        $this->transition($request, RequestStatus::Submitted, TransitionContext::citizen('Podnošenje potvrđeno putem emaila.'));
        $this->notifications->sendSubmissionReceipt($request);
    }

    public function submitSupplement(ApplicationRequest $request): void
    {
        if (!$request->getStatusEnum()->isSupplementRound()) {
            throw new TransitionException('Dopuna trenutno nije moguća.');
        }
        $this->transition($request, RequestStatus::Submitted, TransitionContext::citizen('Podnosilac je dostavio dopunu.'));
    }

    // ------------------------------------------------------------------
    // Šalter
    // ------------------------------------------------------------------

    /**
     * Freshly written counter request (Draft, IntakeChannel=Counter) → Submitted.
     */
    public function registerCounterIntake(ApplicationRequest $request, Member $member): void
    {
        if ($request->getStatusEnum() !== RequestStatus::Draft || !$request->isCounterIntake()) {
            throw new TransitionException('Predmet nije u stanju prijema na šalteru.');
        }
        if (!$request->isServiceReferent($member)) {
            throw new TransitionException('Možete zaprimati samo zahtjeve vlastitih službi.');
        }
        if (trim((string) $request->ProtocolNumber) === '') {
            throw new TransitionException('Za prijem na šalteru broj protokola je obavezan.');
        }
        $request->IntakeByID = (int) $member->ID;
        $this->transition($request, RequestStatus::Submitted, TransitionContext::staff($member, 'Zahtjev zaprimljen na šalteru.'));
        $this->audit->log(AuditLogEntry::ACTION_CREATE, $request, 'Prijem na šalteru', $member);
        if ($request->hasEmail()) {
            $this->notifications->sendCounterReceipt($request);
        }
    }

    // ------------------------------------------------------------------
    // Lock
    // ------------------------------------------------------------------

    /**
     * "Pokreni obradu": Submitted → InProcess (or take over an unlocked InProcess case).
     */
    public function startProcessing(ApplicationRequest $request, Member $member): void
    {
        if (!$request->canStartProcessing($member)) {
            throw new TransitionException('Obradu može pokrenuti samo referent nadležne službe, a predmet ne smije biti zaključan.');
        }
        if ($request->getStatusEnum() === RequestStatus::Submitted) {
            $this->transition($request, RequestStatus::InProcess, TransitionContext::staff($member, 'Pokrenuta obrada.'));
        } else {
            $request->AssignedToID = (int) $member->ID;
            $request->ProcessingStartedAt = $this->now();
            $request->write();
            $this->history($request, RequestStatus::InProcess, RequestStatus::InProcess, 'Obradu preuzeo ' . $member->getName(), TransitionContext::staff($member));
        }
        $this->audit->log(AuditLogEntry::ACTION_LOCK, $request, '', $member);
    }

    public function release(ApplicationRequest $request, Member $member): void
    {
        if (!$request->canRelease($member)) {
            throw new TransitionException('Predmet možete otpustiti samo ako ga Vi obrađujete.');
        }
        $this->unassign($request, $member, 'Referent otpustio predmet.');
    }

    public function unlock(ApplicationRequest $request, Member $supervisor): void
    {
        if (!$request->canUnlock($supervisor)) {
            throw new TransitionException('Otključavanje je dozvoljeno samo nadzoru, za zaključan predmet.');
        }
        $previous = $request->AssignedTo()->getName();
        $this->unassign($request, $supervisor, sprintf('Nadzor otključao predmet (obrađivao: %s).', $previous));
    }

    private function unassign(ApplicationRequest $request, Member $member, string $note): void
    {
        $request->AssignedToID = 0;
        $request->write();
        $this->history($request, RequestStatus::InProcess, RequestStatus::InProcess, $note, TransitionContext::staff($member));
        $this->audit->log(AuditLogEntry::ACTION_UNLOCK, $request, $note, $member);
    }

    // ------------------------------------------------------------------
    // Shortcuts
    // ------------------------------------------------------------------

    public function registerPickup(ApplicationRequest $request, Member $member): void
    {
        if (!$request->canRegisterPickup($member)) {
            throw new TransitionException('Preuzimanje evidentira referent nadležne službe.');
        }
        $this->transition($request, RequestStatus::Completed, TransitionContext::staff($member, 'Stranka preuzela akt.'));
    }

    public function dismissExpiredSupplement(ApplicationRequest $request, ?Member $member = null): void
    {
        if ($member !== null && !$request->canDismissExpiredSupplement($member)) {
            throw new TransitionException('Odbacivanje je moguće tek nakon isteka roka za dopunu, od strane referenta službe.');
        }
        if (!$request->getStatusEnum()->isSupplementRound()
            || !$request->SupplementDueDate
            || $this->deadlines->daysUntil((string) $request->SupplementDueDate) >= 0
        ) {
            throw new TransitionException('Rok za dopunu još nije istekao.');
        }
        $context = $member !== null
            ? new TransitionContext(actorType: TransitionContext::ACTOR_STAFF, member: $member, internalNote: 'Rok za dopunu istekao.', dismissalReason: DismissalReason::SupplementNotProvided)
            : TransitionContext::system('Rok za dopunu istekao – automatsko odbacivanje.', DismissalReason::SupplementNotProvided);
        $this->transition($request, RequestStatus::Dismissed, $context);
    }

    // ------------------------------------------------------------------
    // Core
    // ------------------------------------------------------------------

    public function transition(ApplicationRequest $request, RequestStatus $to, TransitionContext $context): void
    {
        $from = $request->getStatusEnum();
        if (!$from->canTransitionTo($to)) {
            throw new TransitionException(sprintf('Prelaz iz statusa „%s“ u „%s“ nije dozvoljen.', $from->label(), $to->label()));
        }

        $items = $this->assertPreconditions($request, $from, $to, $context);
        $now = $this->now();
        $log = null;

        DB::get_conn()->withTransaction(function () use ($request, $from, $to, $context, $now, $items, &$log): void {
            $this->applyEffects($request, $from, $to, $context, $now, $items);
            $request->applyStatusFromWorkflow($to);
            $request->write();
            $log = $this->history($request, $from, $to, $this->composeLogNote($request, $to, $context), $context);
        });

        if ($context->notifyCitizen && $to !== RequestStatus::Submitted && $request->hasEmail() && $this->notifications->shouldNotifyCitizen($to)) {
            if ($this->notifications->sendStatusChanged($request, $to) && $log instanceof ApplicationStatusChange) {
                $log->CitizenNotified = true;
                $log->write();
            }
        }
        if ($to === RequestStatus::Submitted) {
            $this->notifications->notifyServiceNewRequest($request, $from->isSupplementRound());
        }
        if ($context->actorType !== TransitionContext::ACTOR_CITIZEN) {
            $this->audit->log(AuditLogEntry::ACTION_STATUS, $request, sprintf('%s → %s', $from->value, $to->value), $context->member);
        }

        $request->extend('onAfterStatusTransition', $from, $to, $context);
    }

    /**
     * @return array<int, \YouWillLikeIT\EGov\Enum\SupplementReason> validated supplement items
     */
    private function assertPreconditions(ApplicationRequest $request, RequestStatus $from, RequestStatus $to, TransitionContext $context): array
    {
        $member = $context->member;

        if ($context->actorType === TransitionContext::ACTOR_STAFF) {
            if (!$member) {
                throw new TransitionException('Nepoznat korisnik.');
            }
            $allowed = match (true) {
                $from === RequestStatus::Draft => $request->isCounterIntake() && $request->isServiceReferent($member),
                $from === RequestStatus::Submitted => $request->canStartProcessing($member),
                $from === RequestStatus::InProcess => $request->canDecide($member),
                $from === RequestStatus::ReadyForPickup => $request->canRegisterPickup($member),
                $from->isSupplementRound() => $to === RequestStatus::Dismissed && $request->canDismissExpiredSupplement($member),
                default => false,
            };
            if (!$allowed) {
                throw new TransitionException($from === RequestStatus::InProcess
                    ? 'Odluku donosi samo referent koji obrađuje predmet (nadzor može samo otključati predmet).'
                    : 'Nemate pravo na ovu radnju.');
            }
            if (in_array($to->value, (array) static::config()->get('require_protocol_for'), true) && trim((string) $request->ProtocolNumber) === '') {
                throw new TransitionException('Prije ove radnje unesite broj protokola i sačuvajte predmet.');
            }
        }

        if ($from === RequestStatus::Draft && $to === RequestStatus::Submitted && !$request->isCounterIntake() && !$request->EmailConfirmedAt) {
            throw new TransitionException('Email adresa podnosioca nije potvrđena.');
        }

        $items = [];
        if (in_array($to, [RequestStatus::Incomplete, RequestStatus::AwaitingPayment], true)) {
            $items = $this->notices->validateItems($request, $context->supplementItems);
        }

        if ($to === RequestStatus::Dismissed && $context->dismissalReason === null) {
            throw new TransitionException('Odaberite razlog odbacivanja.');
        }

        if ($to === RequestStatus::ReadyForPickup
            && static::config()->get('require_result_document_for_pickup')
            && !$request->getResultDocument()
        ) {
            throw new TransitionException('Prije statusa „Spreman za preuzimanje“ priložite primjerak izdatog akta.');
        }

        return $items;
    }

    /**
     * @param array<int, \YouWillLikeIT\EGov\Enum\SupplementReason> $items
     */
    private function applyEffects(ApplicationRequest $request, RequestStatus $from, RequestStatus $to, TransitionContext $context, string $now, array $items): void
    {
        $restartDeadline = $from->suspendsDeadline() && !$to->suspendsDeadline() && !$to->isTerminal();

        switch ($to) {
            case RequestStatus::Submitted:
                if (!$request->SubmittedAt) {
                    $request->SubmittedAt = $now;
                }
                $request->AssignedToID = 0;
                break;

            case RequestStatus::InProcess:
                $request->AssignedToID = (int) $context->member?->ID;
                $request->ProcessingStartedAt = $now;
                break;

            case RequestStatus::Incomplete:
            case RequestStatus::AwaitingPayment:
                $days = max(1, $context->supplementDays ?? $this->deadlines->supplementDaysFor((string) $request->RegistryCode));
                $request->SupplementRound = (int) $request->SupplementRound + 1;
                $request->SupplementDueDate = $this->deadlines->supplementDueDateFor($request, $days);
                $request->DecisionDueDate = null;
                $request->AssignedToID = 0; // unlock while waiting for the citizen
                foreach ($items as $docId => $reason) {
                    SupplementItem::create([
                        'RequestID' => $request->ID,
                        'RequiredDocumentID' => $docId,
                        'Reason' => $reason->value,
                        'Round' => $request->SupplementRound,
                        'MemberID' => (int) $context->member?->ID,
                    ])->write();
                }
                $request->SupplementRequestText = $this->notices->render($request, $items, $days);
                break;

            case RequestStatus::Completed:
                $request->CompletedAt = $now;
                if ($from === RequestStatus::ReadyForPickup) {
                    $request->PickedUpAt = $now;
                }
                break;

            case RequestStatus::Rejected:
                $request->RejectionReason = trim($context->rejectionNote);
                $request->CompletedAt = $now;
                break;

            case RequestStatus::Dismissed:
                $request->DismissalReason = $context->dismissalReason?->value;
                $request->CompletedAt = $now;
                break;

            default:
                break;
        }

        if ($restartDeadline) {
            $request->DeadlineStartedAt = $now;
            $request->DecisionDueDate = $this->deadlines->decisionDueDateFor($request);
            $request->SupplementDueDate = null;
        }
    }

    private function history(ApplicationRequest $request, RequestStatus $from, RequestStatus $to, string $note, TransitionContext $context): ApplicationStatusChange
    {
        $log = ApplicationStatusChange::create([
            'RequestID' => $request->ID,
            'FromStatus' => $from->value,
            'ToStatus' => $to->value,
            'Note' => $note,
            'ActorType' => $context->actorType,
            'MemberID' => (int) $context->member?->ID,
        ]);
        $log->write();
        return $log;
    }

    private function composeLogNote(ApplicationRequest $request, RequestStatus $to, TransitionContext $context): string
    {
        $parts = array_filter([
            trim($context->internalNote),
            $to === RequestStatus::Dismissed && $context->dismissalReason ? 'Razlog odbacivanja: ' . $context->dismissalReason->label() : '',
            $to === RequestStatus::Rejected ? trim($context->rejectionNote) : '',
            in_array($to, [RequestStatus::Incomplete, RequestStatus::AwaitingPayment], true) ? 'Dopuna (krug ' . $request->SupplementRound . ')' : '',
        ]);
        return implode("\n", $parts);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp());
    }
}
