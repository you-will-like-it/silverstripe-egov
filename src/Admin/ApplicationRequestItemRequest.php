<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Admin;

use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use YouWillLikeIT\EGov\Controller\SecureFileController;
use YouWillLikeIT\EGov\Enum\DismissalReason;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;
use YouWillLikeIT\EGov\Service\AuditLogger;
use YouWillLikeIT\EGov\Verification\AiDocumentVerificationService;
use YouWillLikeIT\EGov\Workflow\ApplicationWorkflow;
use YouWillLikeIT\EGov\Workflow\TransitionContext;
use YouWillLikeIT\EGov\Workflow\TransitionException;

/**
 * e-Gov actions on the CMS detail form:
 *   Pokreni obradu · Primijeni odluku · Otpusti predmet · Otključaj (nadzor) ·
 *   Evidentiraj preuzimanje · Odbaci (rok za dopunu istekao) · Provjeri priloge ·
 *   PDF zahtjeva · Potvrda o prijemu
 * plus counter intake ("Prijem na šalteru") for new records.
 */
class ApplicationRequestItemRequest extends GridFieldDetailForm_ItemRequest
{
    private static array $allowed_actions = ['edit', 'view', 'ItemEditForm'];

    /** Custom actions that stay clickable when the form is readonly (locked by somebody else). */
    private const ALWAYS_ENABLED = ['action_doEgovStart', 'action_doEgovUnlock', 'action_doEgovPickup', 'action_doEgovDismissExpired', 'action_doEgovVerify'];

    public function edit($request)
    {
        if ($this->record instanceof ApplicationRequest && $this->record->isInDB()) {
            AuditLogger::singleton()->log(AuditLogEntry::ACTION_VIEW, $this->record);
        }
        return parent::edit($request);
    }

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();
        if ($form instanceof Form) {
            foreach (self::ALWAYS_ENABLED as $name) {
                $form->Actions()->dataFieldByName($name)?->setReadonly(false);
            }
        }
        return $form;
    }

    protected function getFormActions()
    {
        $actions = parent::getFormActions();
        $r = $this->record;
        if (!$r instanceof ApplicationRequest || !$r->isInDB()) {
            return $actions;
        }
        $m = Security::getCurrentUser();
        $major = $actions->fieldByName('MajorActions');
        $push = static function (FormAction $a) use ($actions, $major): void {
            $major instanceof CompositeField ? $major->push($a) : $actions->push($a);
        };

        if ($r->canStartProcessing($m)) {
            $push(FormAction::create('doEgovStart', 'Pokreni obradu')->setUseButtonTag(true)->setIcon('lock')->addExtraClass('btn-primary'));
        }
        if ($r->canDecide($m)) {
            $push(FormAction::create('doEgovDecide', 'Primijeni odluku')->setUseButtonTag(true)->setIcon('check-mark-circle')->addExtraClass('btn-primary'));
            $push(FormAction::create('doEgovRelease', 'Otpusti predmet')->setUseButtonTag(true)->setIcon('back-in-time')->addExtraClass('btn-outline-secondary'));
        }
        if ($r->canUnlock($m)) {
            $push(FormAction::create('doEgovUnlock', 'Otključaj predmet (nadzor)')->setUseButtonTag(true)->setIcon('unlock')->addExtraClass('btn-warning'));
        }
        if ($r->canRegisterPickup($m)) {
            $push(FormAction::create('doEgovPickup', 'Evidentiraj preuzimanje')->setUseButtonTag(true)->setIcon('check-mark')->addExtraClass('btn-success'));
        }
        if ($r->canDismissExpiredSupplement($m)) {
            $push(FormAction::create('doEgovDismissExpired', 'Odbaci – rok za dopunu istekao')->setUseButtonTag(true)->setIcon('cross-mark')->addExtraClass('btn-outline-danger'));
        }
        if ($r->canView($m) && $r->evidenceAttachments()->exists()) {
            $push(FormAction::create('doEgovVerify', 'Provjeri priloge')->setUseButtonTag(true)->setIcon('eye')->addExtraClass('btn-outline-secondary'));
        }

        $links = sprintf(
            '<a class="btn btn-outline-secondary" href="%s" target="_blank" rel="noopener"><span class="font-icon-down-circled" aria-hidden="true"></span> PDF zahtjeva (za potpis)</a> ',
            htmlspecialchars(SecureFileController::pdfLink($r), ENT_QUOTES)
        );
        if ($r->ProtocolNumber) {
            $links .= sprintf(
                '<a class="btn btn-outline-secondary" href="%s" target="_blank" rel="noopener"><span class="font-icon-print" aria-hidden="true"></span> Potvrda o prijemu</a>',
                htmlspecialchars(SecureFileController::receiptLink($r), ENT_QUOTES)
            );
        }
        $actions->push(LiteralField::create('EgovLinks', $links));
        return $actions;
    }

    // ------------------------------------------------------------------
    // Save: counter intake + editable counter data
    // ------------------------------------------------------------------

    protected function saveFormIntoRecord($data, $form)
    {
        $member = Security::getCurrentUser();
        $isNew = !$this->record->ID;

        if ($isNew) {
            if (!$member instanceof Member) {
                throw new ValidationException(ValidationResult::create()->addError('Niste prijavljeni.'));
            }
            $procedure = ProcedureDefinition::get()->byID((int) ($data['ProcedureID'] ?? 0));
            $allowed = MunicipalService::idsForMember($member, true);
            if (!$procedure instanceof ProcedureDefinition || !in_array((int) $procedure->ServiceID, $allowed, true)) {
                throw new ValidationException(ValidationResult::create()->addFieldError('ProcedureID', 'Odaberite proceduru vlastite službe.'));
            }
            $this->record->IntakeChannel = ApplicationRequest::CHANNEL_COUNTER;
            $this->record->IntakeByID = (int) $member->ID;
            $data['ClassName'] = $procedure->getRequestClassName();
        }

        $record = parent::saveFormIntoRecord($data, $form);

        if ($record instanceof ApplicationRequest) {
            if ($isNew) {
                try {
                    ApplicationWorkflow::singleton()->registerCounterIntake($record, $member);
                } catch (TransitionException $e) {
                    throw new ValidationException(ValidationResult::create()->addError($e->getMessage()));
                }
            } else {
                if ($record->procedureFieldsEditable($member)) {
                    $record->saveProcedureFormData($data);
                    $record->write();
                }
                AuditLogger::singleton()->log(AuditLogEntry::ACTION_EDIT, $record, 'Izmjena podataka predmeta', $member);
            }
        }
        return $record;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovStart(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r, Member $m): string {
            ApplicationWorkflow::singleton()->startProcessing($r, $m);
            return 'Obrada pokrenuta – predmet je zaključan na Vas.';
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovDecide(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r, Member $m) use ($data, $form): string {
            $p = ApplicationRequest::WORKFLOW_FIELD_PREFIX;
            $target = RequestStatus::tryFrom((string) ($data[$p . 'NewStatus'] ?? ''));
            if ($target === null) {
                throw new TransitionException('Odaberite novi status.');
            }
            // persist regular edits (protocol number, internal note …) first
            $this->saveFormIntoRecord($data, $form);

            $items = [];
            foreach ($data as $key => $value) {
                if (str_starts_with((string) $key, $p . 'Doc_') && (string) $value !== '') {
                    $items[(int) substr((string) $key, strlen($p . 'Doc_'))] = (string) $value;
                }
            }
            ApplicationWorkflow::singleton()->transition($r, $target, new TransitionContext(
                actorType: TransitionContext::ACTOR_STAFF,
                member: $m,
                internalNote: trim((string) ($data[$p . 'Note'] ?? '')),
                supplementItems: $items,
                supplementDays: is_numeric($data[$p . 'SupplementDays'] ?? null) ? (int) $data[$p . 'SupplementDays'] : null,
                dismissalReason: DismissalReason::tryFrom((string) ($data[$p . 'DismissalReason'] ?? '')),
                rejectionNote: trim((string) ($data[$p . 'RejectionReason'] ?? '')),
            ));
            return sprintf('Status promijenjen u „%s“.', $target->label());
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovRelease(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r, Member $m): string {
            ApplicationWorkflow::singleton()->release($r, $m);
            return 'Predmet je otpušten i dostupan drugim referentima.';
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovUnlock(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r, Member $m): string {
            ApplicationWorkflow::singleton()->unlock($r, $m);
            return 'Predmet je otključan.';
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovPickup(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r, Member $m): string {
            ApplicationWorkflow::singleton()->registerPickup($r, $m);
            return 'Preuzimanje evidentirano – predmet je završen.';
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovDismissExpired(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r, Member $m): string {
            ApplicationWorkflow::singleton()->dismissExpiredSupplement($r, $m);
            return 'Zahtjev je odbačen (nedostaci nisu otklonjeni u roku).';
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEgovVerify(array $data, Form $form): mixed
    {
        return $this->run($form, function (ApplicationRequest $r): string {
            $light = AiDocumentVerificationService::singleton()->verifyRequest($r, true);
            return sprintf('Provjera priloga završena: %s %s', $light->icon(), $light->label());
        });
    }

    /**
     * @param callable(ApplicationRequest, Member): string $action
     */
    private function run(Form $form, callable $action): mixed
    {
        $record = $this->record;
        $member = Security::getCurrentUser();
        if (!$record instanceof ApplicationRequest || !$member || !$record->canView($member)) {
            return $this->httpError(403);
        }
        try {
            $form->sessionMessage($action($record, $member), ValidationResult::TYPE_GOOD);
        } catch (TransitionException $e) {
            $form->sessionMessage($e->getMessage(), ValidationResult::TYPE_ERROR);
        } catch (ValidationException $e) {
            $form->setSessionValidationResult($e->getResult());
        }
        return $this->edit($this->getToplevelController()->getRequest());
    }
}
