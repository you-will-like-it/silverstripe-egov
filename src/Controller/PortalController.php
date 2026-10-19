<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FileField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\Requirements;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Form\RequestMultiForm;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Procedure\ProcedureRegistry;
use YouWillLikeIT\EGov\Service\AttachmentUploadService;
use YouWillLikeIT\EGov\Tenant\TenantConfig;
use YouWillLikeIT\EGov\Workflow\ApplicationWorkflow;
use YouWillLikeIT\EGov\Workflow\TransitionException;

/**
 * Public citizen portal (no login).
 *
 *   /e-uprava                         katalog procedura
 *   /e-uprava/zahtjev/{code}          višekoračni obrazac
 *   /e-uprava/predmet/{hash}          prilozi, podnošenje, dopuna (capability URL)
 *   /e-uprava/potvrda/{token}         double-opt-in potvrda
 *   /e-uprava/status                  → StatusController (rate limited)
 */
class PortalController extends Controller
{
    private static array $allowed_actions = [
        'zahtjev',
        'RequestForm',
        'predmet',
        'UploadForm',
        'CaseActionsForm',
        'potvrda',
    ];

    private static array $url_handlers = [
        'zahtjev/$Code!/RequestForm' => 'RequestForm',
        'zahtjev/$Code!' => 'zahtjev',
        'predmet/$Hash!/UploadForm' => 'UploadForm',
        'predmet/$Hash!/CaseActionsForm' => 'CaseActionsForm',
        'predmet/$Hash!' => 'predmet',
        'potvrda/$Token!' => 'potvrda',
    ];

    protected function init(): void
    {
        parent::init();
        Requirements::css('youwilllikeit/silverstripe-egov: client/dist/egov.css');
        Requirements::javascript('youwilllikeit/silverstripe-egov: client/dist/egov.js', ['type' => 'module', 'defer' => true]);
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getResponse()->addHeader('Referrer-Policy', 'no-referrer');
    }

    public function Link($action = null): string
    {
        return Controller::join_links(ProcedureRegistry::singleton()->portalBaseLink(), $action);
    }

    // ------------------------------------------------------------------
    // Katalog
    // ------------------------------------------------------------------

    public function index(HTTPRequest $request): DBHTMLText
    {
        $groups = ArrayList::create();
        foreach (MunicipalService::get() as $service) {
            $procedures = $service->Procedures()->filter('IsActive', true);
            if ($procedures->exists()) {
                $groups->push(ArrayData::create(['Service' => $service, 'Procedures' => $procedures]));
            }
        }
        return $this->renderPage('YouWillLikeIT\\EGov\\Portal\\Catalogue', [
            'Title' => 'Elektronski zahtjevi',
            'Groups' => $groups,
        ]);
    }

    // ------------------------------------------------------------------
    // Obrazac
    // ------------------------------------------------------------------

    public function zahtjev(HTTPRequest $request): DBHTMLText
    {
        $procedure = $this->procedureFromRequest($request);
        $form = $this->buildRequestForm($procedure);

        return $this->renderPage('YouWillLikeIT\\EGov\\Portal\\RequestFormPage', [
            'Title' => $procedure->Title,
            'Procedure' => $procedure,
            'Form' => $form,
            'Steps' => $form->getAllStepsLinear(),
            'Fees' => $procedure->Fees(),
            'Documents' => $procedure->citizenDocuments(),
        ]);
    }

    public function RequestForm(HTTPRequest $request): RequestMultiForm
    {
        return $this->buildRequestForm($this->procedureFromRequest($request));
    }

    private function buildRequestForm(ProcedureDefinition $procedure): RequestMultiForm
    {
        $form = RequestMultiForm::create($this, 'RequestForm', $procedure);
        $base = Controller::join_links($this->Link('zahtjev'), $procedure->Code);
        $form->setFormAction(Controller::join_links($base, 'RequestForm'));
        $form->setDisplayLink($base);
        return $form;
    }

    private function procedureFromRequest(HTTPRequest $request): ProcedureDefinition
    {
        $code = (string) $request->param('Code');
        $procedure = preg_match('/^\d{2}-\d{2,3}$/', $code) ? ProcedureDefinition::getByCode($code) : null;
        if (!$procedure || !$procedure->IsActive) {
            $this->httpError(404, 'Procedura nije pronađena.');
        }
        return $procedure;
    }

    // ------------------------------------------------------------------
    // Predmet: prilozi, podnošenje, dopuna
    // ------------------------------------------------------------------

    public function predmet(HTTPRequest $request): DBHTMLText
    {
        $case = $this->caseFromRequest($request);
        $status = $case->getStatusEnum();

        $documents = ArrayList::create();
        foreach ($case->Procedure()->citizenDocuments() as $doc) {
            $documents->push(ArrayData::create([
                'Document' => $doc,
                'Uploaded' => $case->Attachments()->filter('RequiredDocumentID', $doc->ID),
            ]));
        }

        return $this->renderPage('YouWillLikeIT\\EGov\\Portal\\CasePage', [
            'Title' => $case->Procedure()->Title,
            'Case' => $case,
            'StatusLabel' => $status->label(),
            'StatusTone' => $status->tone(),
            'IsDraft' => $status === RequestStatus::Draft,
            'AwaitingConfirmation' => $status === RequestStatus::Draft && (bool) $case->ConfirmationToken,
            'IsSupplementRound' => in_array($status, [RequestStatus::Incomplete, RequestStatus::AwaitingPayment], true),
            'AcceptsUploads' => $status->acceptsCitizenUploads(),
            'Documents' => $documents,
            'Payments' => $case->getExpectedPayments(),
            'MissingDocuments' => ArrayList::create($case->getMissingMandatoryDocuments()),
            'UploadForm' => $status->acceptsCitizenUploads() ? $this->UploadForm($request) : null,
            'CaseActionsForm' => $status->acceptsCitizenUploads() ? $this->CaseActionsForm($request) : null,
            'StatusLink' => $this->Link('status'),
            'MaxFileSizeMb' => (int) AttachmentUploadService::config()->get('max_file_size_mb'),
            'AllowedExtensions' => implode(', ', (array) AttachmentUploadService::config()->get('allowed_extensions')),
        ]);
    }

    public function UploadForm(HTTPRequest $request): Form
    {
        $case = $this->caseFromRequest($request);
        $docs = $case->Procedure()->citizenDocuments()->map('ID', 'Title')->toArray();

        $file = FileField::create('Attachment', 'Datoteka (PDF, JPG, PNG)');
        $file->setAttribute('accept', implode(',', AttachmentUploadService::singleton()->acceptAttribute()));
        $file->getValidator()->setAllowedExtensions((array) AttachmentUploadService::config()->get('allowed_extensions'));
        $file->getValidator()->setAllowedMaxFileSize(['*' => (int) AttachmentUploadService::config()->get('max_file_size_mb') * 1024 * 1024]);

        $form = Form::create(
            $this,
            'UploadForm',
            FieldList::create(
                DropdownField::create('RequiredDocumentID', 'Vrsta dokumenta', $docs)->setEmptyString('— odaberite —'),
                $file,
            ),
            FieldList::create(
                FormAction::create('doUpload', 'Priloži dokument')->setUseButtonTag(true)->addExtraClass('egov-btn egov-btn--primary')
            ),
            RequiredFieldsValidator::create(['RequiredDocumentID', 'Attachment'])
        );
        $form->setFormAction(Controller::join_links($this->Link('predmet'), $case->PublicHash, 'UploadForm'));
        $form->addExtraClass('egov-form egov-upload-form');
        $form->setEncType(Form::ENC_TYPE_MULTIPART);
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doUpload(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFromRequest($this->getRequest());
        $document = RequiredDocument::get()->byID((int) ($data['RequiredDocumentID'] ?? 0));
        // HTTPRequestBuilder merges $_FILES into the post vars
        $tmpFile = $data['Attachment'] ?? null;

        try {
            if (!$document instanceof RequiredDocument || !is_array($tmpFile)) {
                throw new TransitionException('Odaberite vrstu dokumenta i datoteku.');
            }
            AttachmentUploadService::singleton()->store(
                $case,
                $document,
                $tmpFile,
                $case->getStatusEnum() !== RequestStatus::Draft
            );
            $form->sessionMessage(sprintf('Dokument „%s“ je priložen.', $document->Title), 'good');
        } catch (TransitionException $e) {
            $form->sessionMessage($e->getMessage(), 'error');
        }
        return $this->redirect($case->getPortalLink('predmet') . '#prilozi');
    }

    public function CaseActionsForm(HTTPRequest $request): Form
    {
        $case = $this->caseFromRequest($request);
        $status = $case->getStatusEnum();
        $actions = FieldList::create();

        if ($status === RequestStatus::Draft) {
            $actions->push(
                FormAction::create('doSubmit', $case->ConfirmationToken ? 'Ponovo pošalji link za potvrdu' : 'Podnesi zahtjev')
                    ->setUseButtonTag(true)
                    ->addExtraClass('egov-btn egov-btn--primary')
            );
        } elseif (in_array($status, [RequestStatus::Incomplete, RequestStatus::AwaitingPayment], true)) {
            $actions->push(
                FormAction::create('doSubmitSupplement', 'Pošalji dopunu službi')
                    ->setUseButtonTag(true)
                    ->addExtraClass('egov-btn egov-btn--primary')
            );
        }
        $actions->push(
            // rendered hidden; per-attachment buttons in CasePage.ss submit it with name="action_doRemove" value="{ID}"
            FormAction::create('doRemove', 'Ukloni')
                ->setUseButtonTag(true)
                ->addExtraClass('egov-visually-hidden')
                ->setAttribute('formnovalidate', 'formnovalidate')
                ->setAttribute('tabindex', '-1')
        );

        $form = Form::create(
            $this,
            'CaseActionsForm',
            FieldList::create(HiddenField::create('AttachmentID')),
            $actions
        );
        $form->setFormAction(Controller::join_links($this->Link('predmet'), $case->PublicHash, 'CaseActionsForm'));
        $form->addExtraClass('egov-case-actions');
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doSubmit(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFromRequest($this->getRequest());
        try {
            $sent = ApplicationWorkflow::singleton()->requestConfirmation($case);
            $form->sessionMessage($sent
                ? sprintf('Link za potvrdu poslan je na %s. Zahtjev je podnesen tek kada kliknete na link.', $case->ApplicantEmail)
                : 'Email za potvrdu trenutno nije moguće poslati. Pokušajte ponovo za nekoliko minuta.', $sent ? 'good' : 'error');
        } catch (TransitionException $e) {
            $form->sessionMessage($e->getMessage(), 'error');
        }
        return $this->redirect($case->getPortalLink('predmet'));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doSubmitSupplement(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFromRequest($this->getRequest());
        try {
            ApplicationWorkflow::singleton()->submitSupplement($case);
            $form->sessionMessage('Dopuna je dostavljena službi. O daljem toku bit ćete obaviješteni putem emaila.', 'good');
        } catch (TransitionException $e) {
            $form->sessionMessage($e->getMessage(), 'error');
        }
        return $this->redirect($case->getPortalLink('predmet'));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doRemove(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFromRequest($this->getRequest());
        try {
            $id = (int) ($data['action_doRemove'] ?? 0) ?: (int) ($data['AttachmentID'] ?? 0);
            AttachmentUploadService::singleton()->remove($case, $id);
            $form->sessionMessage('Prilog je uklonjen.', 'good');
        } catch (TransitionException $e) {
            $form->sessionMessage($e->getMessage(), 'error');
        }
        return $this->redirect($case->getPortalLink('predmet') . '#prilozi');
    }

    // ------------------------------------------------------------------
    // Double opt-in
    // ------------------------------------------------------------------

    public function potvrda(HTTPRequest $request): DBHTMLText
    {
        $token = (string) $request->param('Token');
        $case = preg_match('/^[a-f0-9]{64}$/', $token)
            ? ApplicationRequest::get()->filter('ConfirmationToken', $token)->first()
            : null;

        if (!$case instanceof ApplicationRequest) {
            return $this->renderPage('YouWillLikeIT\\EGov\\Portal\\Message', [
                'Title' => 'Link nije važeći',
                'Message' => 'Link za potvrdu nije važeći ili je zahtjev već potvrđen. Provjerite status zahtjeva pomoću koda predmeta.',
                'Tone' => 'warning',
                'StatusLink' => $this->Link('status'),
            ]);
        }

        try {
            ApplicationWorkflow::singleton()->confirmSubmission($case);
        } catch (TransitionException $e) {
            return $this->renderPage('YouWillLikeIT\\EGov\\Portal\\Message', [
                'Title' => 'Potvrda nije uspjela',
                'Message' => $e->getMessage(),
                'Tone' => 'danger',
                'CaseLink' => $case->getPortalLink('predmet'),
            ]);
        }

        return $this->renderPage('YouWillLikeIT\\EGov\\Portal\\Message', [
            'Title' => 'Zahtjev je podnesen',
            'Message' => sprintf(
                'Vaš zahtjev je zaprimljen. Kod predmeta: %s. Sačuvajte ovaj kod – uz JMBG/ID broj služi za praćenje statusa. '
                . 'Gotov dokument preuzimate lično na šalteru, gdje ćete potpisati zahtjev.',
                $case->TrackingCode
            ),
            'Tone' => 'success',
            'TrackingCode' => $case->TrackingCode,
            'CaseLink' => $case->getPortalLink('predmet'),
            'StatusLink' => $this->Link('status'),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function caseFromRequest(HTTPRequest $request): ApplicationRequest
    {
        $hash = (string) $request->param('Hash');
        $case = preg_match('/^[a-f0-9]{64}$/', $hash)
            ? ApplicationRequest::get()->filter('PublicHash', $hash)->first()
            : null;
        if (!$case instanceof ApplicationRequest) {
            $this->httpError(404, 'Predmet nije pronađen.');
        }
        return $case;
    }

    /**
     * Renders $template inside the portal layout. Both are theme-overridable.
     *
     * @param array<string, mixed> $data
     */
    protected function renderPage(string $template, array $data): DBHTMLText
    {
        $data = array_merge(TenantConfig::singleton()->toTemplateData(), [
            'PortalLink' => $this->Link(),
            'StatusPageLink' => $this->Link('status'),
        ], $data);
        $content = $this->customise($data)->renderWith($template);
        return $this->customise(array_merge($data, ['Content' => $content]))
            ->renderWith('YouWillLikeIT\\EGov\\Portal\\PortalPage');
    }
}
