<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FileField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use YouWillLikeIT\EGov\Model\ApplicationAttachment;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Pdf\RequestPdfBuilder;
use YouWillLikeIT\EGov\Service\AttachmentUploadService;
use YouWillLikeIT\EGov\Service\AuditLogger;
use YouWillLikeIT\EGov\Workflow\TransitionException;

/**
 * Staff-only file endpoints (behind CMS login, IP restriction and email OTP):
 *   /egov-datoteke/prilog/{id}          download an attachment
 *   /egov-datoteke/zahtjev-pdf/{id}     official request PDF (signature block)
 *   /egov-datoteke/potvrda-pdf/{id}     counter receipt ("potvrda o prijemu")
 *   /egov-datoteke/upload/{id}          upload scanned documents / issued act
 * Every access is checked against ApplicationRequest permissions and audited.
 */
class SecureFileController extends Controller
{
    private static string $url_segment = 'egov-datoteke';

    private static array $allowed_actions = ['prilog', 'pdf', 'receipt', 'upload', 'UploadForm'];

    private static array $url_handlers = [
        'prilog/$ID!' => 'prilog',
        'zahtjev-pdf/$ID!' => 'pdf',
        'potvrda-pdf/$ID!' => 'receipt',
        'upload/$ID!/UploadForm' => 'UploadForm',
        'upload/$ID!' => 'upload',
    ];

    public static function pdfLink(ApplicationRequest $request): string
    {
        return Controller::join_links('/', static::config()->get('url_segment'), 'zahtjev-pdf', (string) $request->ID);
    }

    public static function receiptLink(ApplicationRequest $request): string
    {
        return Controller::join_links('/', static::config()->get('url_segment'), 'potvrda-pdf', (string) $request->ID);
    }

    public static function uploadLink(ApplicationRequest $request): string
    {
        return Controller::join_links('/', static::config()->get('url_segment'), 'upload', (string) $request->ID);
    }

    public function Link($action = null): string
    {
        return Controller::join_links('/', static::config()->get('url_segment'), $action);
    }

    protected function init(): void
    {
        parent::init();
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function prilog(HTTPRequest $request): HTTPResponse
    {
        $attachment = ApplicationAttachment::get()->byID((int) $request->param('ID'));
        if (!$attachment instanceof ApplicationAttachment) {
            return $this->httpError(404);
        }
        $member = $this->assertAccess($attachment->Request());

        $file = $attachment->File();
        if (!$file->exists()) {
            return $this->httpError(404);
        }
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_DOWNLOAD, $attachment->Request(), (string) $attachment->OriginalFilename, $member);
        $inline = in_array((string) $attachment->MimeType, ['application/pdf', 'image/jpeg', 'image/png'], true);
        return $this->secureResponse((string) $file->getString(), (string) ($attachment->MimeType ?: 'application/octet-stream'), (string) $attachment->OriginalFilename, $inline);
    }

    public function pdf(HTTPRequest $request): HTTPResponse
    {
        $record = $this->recordFromRequest($request);
        $member = $this->assertAccess($record);
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_PDF, $record, 'PDF zahtjeva', $member);
        $builder = RequestPdfBuilder::singleton();
        return $this->secureResponse($builder->build($record), 'application/pdf', $builder->filename($record), true);
    }

    public function receipt(HTTPRequest $request): HTTPResponse
    {
        $record = $this->recordFromRequest($request);
        $member = $this->assertAccess($record);
        AuditLogger::singleton()->log(AuditLogEntry::ACTION_PDF, $record, 'Potvrda o prijemu', $member);
        $builder = RequestPdfBuilder::singleton();
        return $this->secureResponse($builder->buildReceipt($record), 'application/pdf', 'potvrda-' . $builder->filename($record), true);
    }

    public function upload(HTTPRequest $request): DBHTMLText
    {
        $record = $this->recordFromRequest($request);
        $member = $this->assertAccess($record);
        Requirements::css('youwilllikeit/silverstripe-egov: client/dist/egov.css');

        return $this->customise([
            'Title' => 'Prilozi predmeta ' . $record->TrackingCode,
            'Case' => $record,
            'Attachments' => $record->Attachments(),
            'CanUpload' => $record->canEdit($member),
            'UploadForm' => $record->canEdit($member) ? $this->UploadForm($request) : null,
        ])->renderWith('YouWillLikeIT\\EGov\\Admin\\StaffUpload');
    }

    public function UploadForm(HTTPRequest $request): Form
    {
        $record = $this->recordFromRequest($request);
        $this->assertAccess($record);

        $docs = $record->Procedure()->RequiredDocuments()->map('ID', 'Title')->toArray();
        $kinds = [ApplicationAttachment::KIND_STAFF => 'Dokument stranke (sken sa šaltera)'];
        if (in_array($record->getStatusEnum()->value, ['InProcess', 'ReadyForPickup', 'Completed'], true)) {
            $kinds[ApplicationAttachment::KIND_RESULT] = 'Primjerak izdatog akta (rješenje, uvjerenje …)';
        }
        $file = FileField::create('Attachment', 'Datoteka (PDF, JPG, PNG)');
        $file->getValidator()->setAllowedExtensions((array) AttachmentUploadService::config()->get('allowed_extensions'));
        $file->getValidator()->setAllowedMaxFileSize(['*' => (int) AttachmentUploadService::config()->get('max_file_size_mb') * 1024 * 1024]);

        $form = Form::create(
            $this,
            'UploadForm',
            FieldList::create(
                OptionsetField::create('Kind', 'Vrsta priloga', $kinds, ApplicationAttachment::KIND_STAFF),
                DropdownField::create('RequiredDocumentID', 'Dokument iz kataloga procedure', $docs)
                    ->setEmptyString('— odaberite (za dokument stranke) —'),
                $file,
            ),
            FieldList::create(FormAction::create('doUpload', 'Priloži')->setUseButtonTag(true)->addExtraClass('egov-btn egov-btn--primary')),
            RequiredFieldsValidator::create(['Kind', 'Attachment'])
        );
        $form->setFormAction(Controller::join_links(self::uploadLink($record), 'UploadForm'));
        $form->setEncType(Form::ENC_TYPE_MULTIPART);
        $form->addExtraClass('egov-form egov-upload-form');
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doUpload(array $data, Form $form): HTTPResponse
    {
        $record = $this->recordFromRequest($this->getRequest());
        $member = $this->assertAccess($record);
        $kind = ($data['Kind'] ?? '') === ApplicationAttachment::KIND_RESULT ? ApplicationAttachment::KIND_RESULT : ApplicationAttachment::KIND_STAFF;
        $doc = RequiredDocument::get()->byID((int) ($data['RequiredDocumentID'] ?? 0));
        try {
            $tmp = $data['Attachment'] ?? null;
            if (!is_array($tmp)) {
                throw new TransitionException('Odaberite datoteku.');
            }
            $attachment = AttachmentUploadService::singleton()->storeStaff($record, $doc instanceof RequiredDocument ? $doc : null, $tmp, $kind, $member);
            AuditLogger::singleton()->log(AuditLogEntry::ACTION_UPLOAD, $record, $attachment->getDocumentLabel() . ': ' . $attachment->OriginalFilename, $member);
            $form->sessionMessage('Prilog je dodan. Osvježite predmet u CMS-u.', 'good');
        } catch (TransitionException $e) {
            $form->sessionMessage($e->getMessage(), 'error');
        }
        return $this->redirect(self::uploadLink($record));
    }

    private function recordFromRequest(HTTPRequest $request): ApplicationRequest
    {
        $record = ApplicationRequest::get()->byID((int) $request->param('ID'));
        if (!$record instanceof ApplicationRequest) {
            $this->httpError(404);
        }
        return $record;
    }

    private function assertAccess(ApplicationRequest $record): Member
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            throw new HTTPResponse_Exception(Security::permissionFailure($this));
        }
        if (!$record->canView($member)) {
            $this->httpError(403);
        }
        return $member;
    }

    private function secureResponse(string $body, string $mime, string $filename, bool $inline): HTTPResponse
    {
        $safeName = (string) preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) ?: 'datoteka';
        $response = HTTPResponse::create($body, 200);
        $response->addHeader('Content-Type', $mime);
        $response->addHeader('Content-Disposition', sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            $safeName,
            rawurlencode($filename)
        ));
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }
}
