<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Report;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Pdf\PdfRendererInterface;
use YouWillLikeIT\EGov\Service\AuditLogger;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * /egov-izvjestaji – PDF statistics per year and branch (linked from the CMS menu).
 * Scope: supervisors see all services, referents only their own.
 */
class ReportController extends Controller
{
    private static string $url_segment = 'egov-izvjestaji';

    private static array $allowed_actions = ['pdf'];

    public function Link($action = null): string
    {
        return Controller::join_links('/', static::config()->get('url_segment'), $action);
    }

    protected function init(): void
    {
        parent::init();
        $member = Security::getCurrentUser();
        if (!$member) {
            throw new HTTPResponse_Exception(Security::permissionFailure($this));
        }
        if (!Permission::checkMember($member, ['ADMIN', ApplicationRequest::PERMISSION_REPORTS])) {
            $this->httpError(403, 'Nemate pravo na izvještaje.');
        }
        Requirements::css('youwilllikeit/silverstripe-egov: client/dist/egov.css');
        $this->getResponse()->addHeader('Cache-Control', 'private, no-store');
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        return $this->customise([
            'Title' => 'Izvještaji e-Uprave',
            'TenantFullName' => TenantConfig::singleton()->fullName(),
            'Form' => $this->filterForm(),
        ])->renderWith('YouWillLikeIT\\EGov\\Admin\\ReportPage');
    }

    public function pdf(HTTPRequest $request): HTTPResponse
    {
        $member = Security::getCurrentUser();
        $year = (int) ($request->getVar('Year') ?: date('Y', DBDatetime::now()->getTimestamp()));
        $branch = array_key_exists((string) $request->getVar('Branch'), ReportBuilder::branchOptions())
            ? (string) $request->getVar('Branch')
            : ReportBuilder::BRANCH_ALL;

        $scope = $this->scope($member);
        $serviceId = (int) $request->getVar('ServiceID');
        if ($serviceId > 0) {
            $scope = in_array($serviceId, $scope, true) ? [$serviceId] : [];
        }

        $sections = ReportBuilder::singleton()->build($year, $branch, $scope);
        $tenant = TenantConfig::singleton();
        $scopeLabel = $serviceId > 0
            ? (string) MunicipalService::get()->byID($serviceId)?->getTitleWithCode()
            : (count($scope) === MunicipalService::get()->count() ? 'Sve službe' : implode(', ', MunicipalService::get()->filter('ID', $scope ?: [0])->column('Title')));

        $html = (string) SSViewer::create('YouWillLikeIT\\EGov\\Pdf\\ReportDocument')->process(ArrayData::create(array_merge($tenant->toTemplateData(), [
            'Year' => $year,
            'BranchLabel' => ReportBuilder::branchOptions()[$branch],
            'ScopeLabel' => $scopeLabel,
            'Sections' => $sections,
            'GeneratedBy' => $member?->getName(),
            'GeneratedAt' => date('d.m.Y. H:i', DBDatetime::now()->getTimestamp()),
            'LetterheadLines' => ArrayList::create(array_map(static fn (string $l): ArrayData => ArrayData::create(['Line' => $l]), $tenant->letterheadLines())),
        ])));

        AuditLogger::singleton()->log(AuditLogEntry::ACTION_REPORT, null, sprintf('%d / %s / %s', $year, $branch, $scopeLabel), $member);

        $pdf = Injector::inst()->get(PdfRendererInterface::class)->render($html, [
            'title' => sprintf('Izvještaj %d – %s', $year, ReportBuilder::branchOptions()[$branch]),
            'author' => $tenant->fullName(),
            'footer' => $tenant->fullName() . ' · Izvještaj e-Uprave ' . $year,
        ]);

        $response = HTTPResponse::create($pdf, 200);
        $response->addHeader('Content-Type', 'application/pdf');
        $response->addHeader('Content-Disposition', sprintf('inline; filename="izvjestaj-%d-%s.pdf"', $year, $branch));
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    private function filterForm(): Form
    {
        $member = Security::getCurrentUser();
        $current = (int) date('Y', DBDatetime::now()->getTimestamp());
        $years = [];
        for ($y = $current; $y >= $current - 5; $y--) {
            $years[$y] = (string) $y;
        }
        $services = MunicipalService::get()->filter('ID', $this->scope($member) ?: [0])->map('ID', 'TitleWithCode');

        $form = Form::create(
            $this,
            'ReportForm',
            FieldList::create(
                DropdownField::create('Year', 'Godina', $years, $current),
                DropdownField::create('Branch', 'Grana', ReportBuilder::branchOptions(), ReportBuilder::BRANCH_ALL),
                DropdownField::create('ServiceID', 'Služba', $services)->setEmptyString('— sve dostupne službe —'),
            ),
            FieldList::create(FormAction::create('generate', 'Generiši PDF')->setUseButtonTag(true)->addExtraClass('egov-btn egov-btn--primary'))
        );
        $form->setFormMethod('GET');
        $form->setFormAction($this->Link('pdf'));
        $form->disableSecurityToken();
        $form->setAttribute('target', '_blank');
        $form->addExtraClass('egov-form');
        return $form;
    }

    /**
     * @return list<int>
     */
    private function scope(?Member $member): array
    {
        return MunicipalService::idsForMember($member);
    }
}
