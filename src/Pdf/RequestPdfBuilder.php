<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Pdf;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\View\SSViewer;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * Official, pre-filled request PDF ("Zahtjev") with the signature block the
 * citizen signs at the counter when picking up the final document.
 * Because the portal has no login, this signature is the formal confirmation
 * of the electronically submitted request.
 */
class RequestPdfBuilder
{
    use Injectable;

    public function filename(ApplicationRequest $request): string
    {
        return sprintf('zahtjev-%s.pdf', strtolower((string) $request->TrackingCode));
    }

    /**
     * "Potvrda o prijemu" for counter intake: tracking code + where to check the status.
     */
    public function buildReceipt(ApplicationRequest $request): string
    {
        $tenant = TenantConfig::singleton();
        $logo = $tenant->logo();
        $data = ArrayData::create(array_merge($tenant->toTemplateData(), [
            'Request' => $request,
            'LetterheadLines' => \SilverStripe\Model\List\ArrayList::create(array_map(
                static fn (string $l): ArrayData => ArrayData::create(['Line' => $l]),
                $tenant->letterheadLines()
            )),
            'LogoDataUri' => $logo ? $this->dataUri($logo->getString() ?? '', (string) $logo->getMimeType()) : null,
            'StatusUrl' => $request->getAbsoluteStatusPageLink(),
            'ProtocolDate' => $request->formatDate((string) $request->ProtocolDate),
            'SubmittedDate' => $request->formatDateTime((string) $request->SubmittedAt),
            'IntakeBy' => $request->IntakeBy()->exists() ? $request->IntakeBy()->getName() : '',
            'PrintedAt' => date('d.m.Y. H:i', DBDatetime::now()->getTimestamp()),
        ]));
        $html = (string) SSViewer::create('YouWillLikeIT\\EGov\\Pdf\\ReceiptDocument')->process($data);
        return Injector::inst()->get(PdfRendererInterface::class)->render($html, [
            'title' => 'Potvrda o prijemu zahtjeva',
            'author' => $tenant->fullName(),
            'footer' => $tenant->fullName() . ' · Kod predmeta: ' . $request->TrackingCode,
        ]);
    }

    public function build(ApplicationRequest $request): string
    {
        $tenant = TenantConfig::singleton();
        $logo = $tenant->logo();

        $data = ArrayData::create(array_merge($tenant->toTemplateData(), [
            'Request' => $request,
            'LetterheadLines' => \SilverStripe\Model\List\ArrayList::create(array_map(
                static fn (string $l): ArrayData => ArrayData::create(['Line' => $l]),
                $tenant->letterheadLines()
            )),
            'LogoDataUri' => $logo ? $this->dataUri($logo->getString() ?? '', (string) $logo->getMimeType()) : null,
            'Service' => $request->Service(),
            'Procedure' => $request->Procedure(),
            'SubjectLine' => $request->getSubjectLine(),
            'IntroText' => $request->getPdfIntroText(),
            'Summary' => $request->getProcedureSummary(),
            'Attachments' => $request->evidenceAttachments(),
            'Payments' => $request->getExpectedPayments(),
            'ApplicantTypeLabel' => $request->getApplicantTypeEnum()->label(),
            'IdLabel' => $request->getApplicantTypeEnum()->identificationLabel(),
            'IsCompany' => $request->getApplicantTypeEnum()->value === 'Company',
            'SubmittedDate' => $request->formatDateTime((string) $request->SubmittedAt),
            'ProtocolDate' => $request->formatDate((string) $request->ProtocolDate),
            'PrintedAt' => date('d.m.Y. H:i', DBDatetime::now()->getTimestamp()),
            'CadastralSummary' => $request->getCadastralSummary(),
        ]));

        $html = (string) SSViewer::create('YouWillLikeIT\\EGov\\Pdf\\RequestDocument')->process($data);

        $footer = trim(sprintf(
            '%s · %s · Kod predmeta: %s%s',
            $tenant->fullName(),
            $tenant->address(),
            $request->TrackingCode,
            $tenant->pdfFooter() !== '' ? ' · ' . $tenant->pdfFooter() : ''
        ));

        return Injector::inst()->get(PdfRendererInterface::class)->render($html, [
            'title' => $request->getSubjectLine(),
            'author' => $tenant->fullName(),
            'footer' => $footer,
        ]);
    }

    private function dataUri(string $binary, string $mime): ?string
    {
        if ($binary === '' || !str_starts_with($mime, 'image/')) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }
}
