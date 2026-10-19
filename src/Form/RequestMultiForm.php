<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\Form;
use SilverStripe\MultiForm\Forms\MultiForm;
use SilverStripe\MultiForm\Models\MultiFormStep;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use YouWillLikeIT\EGov\Form\Step\ApplicantStep;
use YouWillLikeIT\EGov\Form\Step\DetailsStep;
use YouWillLikeIT\EGov\Form\Step\LocationStep;
use YouWillLikeIT\EGov\Form\Step\ReviewStep;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;

/**
 * Multi-step portal form (silverstripe/multiform, SS6 branch / own fork):
 *   Podnosilac → [Lokacija] → Podaci o zahtjevu → Pregled i saglasnost
 *
 * finish() creates the ApplicationRequest in status Draft and wipes the
 * multiform session (it contains JMBG) – attachments and the double-opt-in
 * happen on the case page (/e-uprava/predmet/{hash}).
 */
class RequestMultiForm extends MultiForm
{
    private static string|null $start_step = ApplicantStep::class;

    private static array $ignored_fields = [
        'url',
        'executeForm',
        'SecurityID',
    ];

    public function __construct(
        Controller $controller,
        string $name,
        private readonly ProcedureDefinition $procedure,
    ) {
        parent::__construct($controller, $name);
        $this->addExtraClass('egov-form egov-multiform');
    }

    public function getProcedure(): ProcedureDefinition
    {
        return $this->procedure;
    }

    /**
     * Upstream prev() jumps to the newest saved step; with the optional
     * LocationStep we need the step that actually points to the current one.
     */
    public function prev(array $data, Form $form)
    {
        $this->save($form->getData());
        $prev = $this->getCurrentStep()->getPreviousStepFromDatabase();
        if (!$prev instanceof MultiFormStep) {
            $this->controller->redirectBack();
            return false;
        }
        $this->setCurrentStep($prev);
        return $this->controller->redirect($prev->Link());
    }

    public function finish(array $data, Form $form): bool
    {
        if (!parent::finish($data, $form)) {
            return false;
        }

        $request = $this->buildRequestFromSteps(true);

        $validation = $request->validate();
        if (!$validation->isValid()) {
            $form->sessionMessage(implode(' ', array_column($validation->getMessages(), 'message')), 'error');
            $this->controller->redirectBack();
            return false;
        }

        DB::get_conn()->withTransaction(function () use ($request): void {
            $request->write();
            // multiform data contains personal data – remove it as soon as it is persisted properly
            $session = $this->getMultiFormSession();
            $session?->delete();
        });

        $this->controller->redirect($request->getPortalLink('predmet'));
        return true;
    }

    /**
     * Hydrates an (unsaved) request from all saved steps.
     */
    public function buildRequestFromSteps(bool $forWrite): ApplicationRequest
    {
        $class = $this->procedure->getRequestClassName();
        /** @var ApplicationRequest $request */
        $request = $class::create();
        $request->ProcedureID = $this->procedure->ID;
        $request->RegistryCode = (string) $this->procedure->Code;
        $request->ServiceClass = (string) $this->procedure->ServiceClass;

        $applicant = $this->stepData(ApplicantStep::class);
        foreach ([
            'ApplicantType', 'ApplicantFullName', 'ApplicantParentName', 'ApplicantIdentificationNumber',
            'RepresentativeName', 'ApplicantAddress', 'ApplicantPostalCode', 'ApplicantCity',
            'ApplicantPhone', 'ApplicantEmail',
        ] as $field) {
            if (array_key_exists($field, $applicant)) {
                $request->setField($field, mb_substr(trim((string) $applicant[$field]), 0, 255));
            }
        }

        if ($this->procedure->RequiresLocation) {
            $location = $this->stepData(LocationStep::class);
            foreach (['LocationPlace', 'LocationStreet', 'CadastralParcelNew', 'CadastralParcelOld'] as $field) {
                if (array_key_exists($field, $location)) {
                    $request->setField($field, mb_substr(trim((string) $location[$field]), 0, 255));
                }
            }
            $request->CadastralMunicipalityNewID = (int) ($location['CadastralMunicipalityNewID'] ?? 0);
            $request->CadastralMunicipalityOldID = (int) ($location['CadastralMunicipalityOldID'] ?? 0);
        }

        $request->saveProcedureFormData($this->stepData(DetailsStep::class));

        if ($forWrite) {
            $review = $this->stepData(ReviewStep::class);
            if (!empty($review['PrivacyConsent'])) {
                $request->PrivacyConsentAt = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp());
            }
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepData(string $stepClass): array
    {
        $step = $this->getSavedStepByClass($stepClass);
        return $step instanceof MultiFormStep ? $step->loadData() : [];
    }
}
