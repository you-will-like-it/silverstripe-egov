<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Service;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Upload;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\MimeValidator\MimeUploadValidator;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Enum\VerificationLight;
use YouWillLikeIT\EGov\Model\ApplicationAttachment;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\RequiredDocument;
use YouWillLikeIT\EGov\Workflow\TransitionException;

/**
 * Stores citizen uploads as protected assets (never published) and links them
 * to the request. Content type is verified with silverstripe/mimevalidator.
 *
 * Since silverstripe/assets 3.2 (CVE-2026-24749) access to protected files is no
 * longer granted by default – this module never relies on asset grants and always
 * streams files through SecureFileController after a canView() check.
 */
class AttachmentUploadService
{
    use Injectable;
    use Configurable;

    /**
     * @config
     * @var list<string>
     */
    private static array $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];

    /**
     * @config
     */
    private static int $max_file_size_mb = 10;

    /**
     * @config
     */
    private static int $max_files_per_request = 30;

    /**
     * @config
     */
    private static string $root_folder = 'egov-prilozi';

    /**
     * Citizen upload through the portal.
     *
     * @param array{name: string, type?: string, tmp_name: string, error: int, size: int} $tmpFile
     */
    public function store(
        ApplicationRequest $request,
        RequiredDocument $document,
        array $tmpFile,
        bool $isSupplement = false,
    ): ApplicationAttachment {
        if (!$request->getStatusEnum()->acceptsCitizenUploads()) {
            throw new TransitionException('Prilozi se u ovom statusu ne mogu dodavati.');
        }
        return $this->persist($request, $document, $tmpFile, ApplicationAttachment::KIND_CITIZEN, $isSupplement, null);
    }

    /**
     * Referent upload (scanned counter documents or the issued act).
     * $document null + KIND_RESULT = copy of the issued act.
     *
     * @param array{name: string, type?: string, tmp_name: string, error: int, size: int} $tmpFile
     */
    public function storeStaff(
        ApplicationRequest $request,
        ?RequiredDocument $document,
        array $tmpFile,
        string $kind,
        Member $member,
    ): ApplicationAttachment {
        if (!$request->canEdit($member)) {
            throw new TransitionException('Nemate pravo dodavati priloge ovom predmetu.');
        }
        if ($kind === ApplicationAttachment::KIND_STAFF && !$document) {
            throw new TransitionException('Odaberite vrstu dokumenta.');
        }
        return $this->persist($request, $kind === ApplicationAttachment::KIND_RESULT ? null : $document, $tmpFile, $kind, false, $member);
    }

    /**
     * @param array{name: string, type?: string, tmp_name: string, error: int, size: int} $tmpFile
     */
    private function persist(
        ApplicationRequest $request,
        ?RequiredDocument $document,
        array $tmpFile,
        string $kind,
        bool $isSupplement,
        ?Member $member,
    ): ApplicationAttachment {
        if ($document && (int) $document->ProcedureID !== (int) $request->ProcedureID) {
            throw new TransitionException('Vrsta dokumenta ne pripada ovoj proceduri.');
        }
        if ($request->Attachments()->count() >= (int) static::config()->get('max_files_per_request')) {
            throw new TransitionException('Dostignut je maksimalan broj priloga za ovaj zahtjev.');
        }
        if (($tmpFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $tmpFile['tmp_name'])) {
            throw new TransitionException('Datoteka nije ispravno učitana. Pokušajte ponovo.');
        }

        $hash = (string) hash_file('sha256', (string) $tmpFile['tmp_name']);
        if ($request->Attachments()->filter('Sha256', $hash)->exists()) {
            throw new TransitionException('Ova datoteka je već priložena uz zahtjev.');
        }

        $upload = Upload::create();
        $validator = class_exists(MimeUploadValidator::class) ? MimeUploadValidator::create() : $upload->getValidator();
        $validator->setAllowedExtensions((array) static::config()->get('allowed_extensions'));
        $validator->setAllowedMaxFileSize(['*' => (int) static::config()->get('max_file_size_mb') * 1024 * 1024]);
        $upload->setValidator($validator);
        $upload->setDefaultVisibility(AssetStore::VISIBILITY_PROTECTED);

        $ext = strtolower(pathinfo((string) $tmpFile['name'], PATHINFO_EXTENSION));
        $fileClass = File::get_class_for_file_extension($ext);
        /** @var File $file */
        $file = $fileClass::create();

        $originalName = mb_substr(basename((string) $tmpFile['name']), 0, 255);
        $tmpFile['name'] = $this->safeFilename($document, $kind, $ext);
        if (!$upload->loadIntoFile($tmpFile, $file, $this->folderPath($request))) {
            $errors = $upload->getErrors();
            throw new TransitionException($errors !== [] ? implode(' ', $errors) : 'Datoteka nije prihvaćena.');
        }
        $file->protectFile();

        return $this->attach($request, $document, $file, $kind, $isSupplement, $member, $originalName, $hash);
    }

    /**
     * Attach content generated in code (demo data, imports, documents rendered by the system).
     * Same storage rules as uploads: protected asset, duplicate check, per-request limit.
     */
    public function storeGenerated(
        ApplicationRequest $request,
        ?RequiredDocument $document,
        string $content,
        string $originalName,
        string $kind = ApplicationAttachment::KIND_CITIZEN,
        ?Member $member = null,
        bool $isSupplement = false,
    ): ApplicationAttachment {
        if ($kind === ApplicationAttachment::KIND_CITIZEN && !$request->getStatusEnum()->acceptsCitizenUploads()) {
            throw new TransitionException('Prilozi se u ovom statusu ne mogu dodavati.');
        }
        if ($document && (int) $document->ProcedureID !== (int) $request->ProcedureID) {
            throw new TransitionException('Vrsta dokumenta ne pripada ovoj proceduri.');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, (array) static::config()->get('allowed_extensions'), true)) {
            throw new TransitionException(sprintf('Tip datoteke .%s nije dozvoljen.', $ext));
        }
        if ($request->Attachments()->count() >= (int) static::config()->get('max_files_per_request')) {
            throw new TransitionException('Dostignut je maksimalan broj priloga za ovaj zahtjev.');
        }
        $hash = hash('sha256', $content);
        if ($request->Attachments()->filter('Sha256', $hash)->exists()) {
            throw new TransitionException('Ova datoteka je već priložena uz zahtjev.');
        }

        $folder = Folder::find_or_make($this->folderPath($request));
        $fileClass = File::get_class_for_file_extension($ext);
        /** @var File $file */
        $file = $fileClass::create();
        $name = $this->safeFilename($kind === ApplicationAttachment::KIND_RESULT ? null : $document, $kind, $ext);
        $file->ParentID = (int) $folder->ID;
        $file->setFromString($content, rtrim((string) $folder->getFilename(), '/') . '/' . $name, null, null, [
            'visibility' => AssetStore::VISIBILITY_PROTECTED,
        ]);
        $file->write();
        $file->protectFile();

        return $this->attach(
            $request,
            $kind === ApplicationAttachment::KIND_RESULT ? null : $document,
            $file,
            $kind,
            $isSupplement,
            $member,
            mb_substr(basename($originalName), 0, 255),
            $hash
        );
    }

    private function attach(
        ApplicationRequest $request,
        ?RequiredDocument $document,
        File $file,
        string $kind,
        bool $isSupplement,
        ?Member $member,
        string $originalName,
        string $hash,
    ): ApplicationAttachment {
        $attachment = ApplicationAttachment::create([
            'RequestID' => $request->ID,
            'FileID' => $file->ID,
            'RequiredDocumentID' => (int) $document?->ID,
            'Kind' => $kind,
            'UploadedByID' => (int) $member?->ID,
            'OriginalFilename' => $originalName,
            'MimeType' => mb_substr((string) $file->getMimeType(), 0, 100),
            'FileSize' => (int) $file->getAbsoluteSize(),
            'Sha256' => $hash,
            'IsSupplement' => $isSupplement,
            // issued acts are not verified
            'VerificationLight' => $kind === ApplicationAttachment::KIND_RESULT ? VerificationLight::Green->value : VerificationLight::Pending->value,
        ]);
        $attachment->write();

        if ($kind !== ApplicationAttachment::KIND_RESULT) {
            $request->AiOverallLight = VerificationLight::Pending->value;
            $request->write();
        }

        return $attachment;
    }

    public function remove(ApplicationRequest $request, int $attachmentId): void
    {
        if (!$request->getStatusEnum()->acceptsCitizenUploads()) {
            throw new TransitionException('Prilozi se u ovom statusu ne mogu uklanjati.');
        }
        $attachment = $request->Attachments()->byID($attachmentId);
        if (!$attachment) {
            return;
        }
        if ($attachment->Kind !== ApplicationAttachment::KIND_CITIZEN) {
            throw new TransitionException('Ovaj prilog je dodala služba i ne može se ukloniti.');
        }
        // Only attachments added in the current round may be removed by the citizen.
        if ($request->getStatusEnum() !== RequestStatus::Draft) {
            $roundStart = $request->StatusChanges()
                ->filter('ToStatus', [RequestStatus::Incomplete->value, RequestStatus::AwaitingPayment->value])
                ->sort('Created', 'DESC')
                ->first();
            if (!$roundStart || strtotime((string) $attachment->Created) < strtotime((string) $roundStart->Created)) {
                throw new TransitionException('Prilozi koje je služba već zaprimila ne mogu se ukloniti.');
            }
        }
        $attachment->File()->deleteFile();
        $attachment->File()->delete();
        $attachment->delete();
    }

    /**
     * @return list<string>
     */
    public function acceptAttribute(): array
    {
        return array_map(static fn (string $e): string => '.' . $e, (array) static::config()->get('allowed_extensions'));
    }

    private function folderPath(ApplicationRequest $request): string
    {
        $root = trim((string) static::config()->get('root_folder'), '/');
        $this->ensureRootFolderIsRestricted($root);
        $now = DBDatetime::now();
        return sprintf('%s/%s/%s/%s', $root, date('Y', $now->getTimestamp()), date('m', $now->getTimestamp()), substr((string) $request->PublicHash, 0, 16));
    }

    private function ensureRootFolderIsRestricted(string $root): void
    {
        $folder = Folder::find_or_make($root);
        if ($folder->CanViewType !== InheritedPermissions::ONLY_THESE_USERS) {
            $folder->CanViewType = InheritedPermissions::ONLY_THESE_USERS; // no groups → admins only in Files
            $folder->write();
        }
    }

    private function safeFilename(?RequiredDocument $document, string $kind, string $ext): string
    {
        $base = $document
            ? (RequiredDocument::slug((string) ($document->DocumentKey ?: $document->Title)) ?: 'prilog')
            : ($kind === ApplicationAttachment::KIND_RESULT ? 'izdati-akt' : 'prilog');
        return sprintf('%s-%s.%s', $base, bin2hex(random_bytes(4)), $ext);
    }
}
