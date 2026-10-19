<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\Search\SearchContext;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Enum\VerificationLight;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;
use YouWillLikeIT\EGov\Service\AuditLogger;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;

/**
 * "e-Zahtjevi" – inbox for municipal referents.
 * Referents see only requests of their own service(s); supervisors see all.
 * Unconfirmed drafts (no double-opt-in yet) are hidden.
 */
class ApplicationRequestAdmin extends ModelAdmin
{
    private static string $url_segment = 'e-zahtjevi';

    private static string $menu_title = 'e-Zahtjevi';

    private static string $menu_icon_class = 'font-icon-p-list';

    private static int $menu_priority = 9;

    private static array $managed_models = [
        'requests' => [
            'dataClass' => ApplicationRequest::class,
            'title' => 'Zahtjevi',
        ],
    ];

    private static array $required_permission_codes = [
        ApplicationRequest::PERMISSION_REFERENT,
        ApplicationRequest::PERMISSION_SUPERVISOR,
    ];

    private static int $page_length = 30;

    public $showImportForm = false;

    // AdminController::canView requires ALL codes; here any one is enough.
    public function canView($member = null)
    {
        return Permission::checkMember($member ?? Security::getCurrentUser(), array_merge(
            ['CMS_ACCESS_LeftAndMain'],
            (array) static::getRequiredPermissions()
        ));
    }

    protected function init()
    {
        parent::init();
        Requirements::css('youwilllikeit/silverstripe-egov: client/dist/egov-admin.css');
    }

    public function getList()
    {
        /** @var DataList<ApplicationRequest> $list */
        $list = parent::getList();
        $list = $list->exclude('Status', RequestStatus::Draft->value);

        $serviceIds = MunicipalService::idsForMember();
        if ($serviceIds === []) {
            return $list->filter('ID', 0);
        }

        // audit searches by JMBG/ID (personal data lookup)
        $filters = (array) ($this->getRequest()->requestVar('filter') ?? []);
        foreach ($filters as $values) {
            $jmbg = is_array($values) ? (string) ($values['ApplicantIdentificationNumber'] ?? '') : '';
            if ($jmbg !== '') {
                AuditLogger::singleton()->log(AuditLogEntry::ACTION_SEARCH, null, 'JMBG/ID: ' . substr($jmbg, 0, 2) . '…' . substr($jmbg, -3));
            }
        }

        return $list->filter('ServiceID', $serviceIds);
    }

    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->removeComponentsByType([GridFieldDeleteAction::class]);
        $config->getComponentByType(GridFieldAddNewButton::class)?->setButtonName('Prijem zahtjeva na šalteru');

        $detail = $config->getComponentByType(GridFieldDetailForm::class);
        $detail?->setItemRequestClass(ApplicationRequestItemRequest::class);

        $filter = $config->getComponentByType(GridFieldFilterHeader::class);
        if ($filter instanceof GridFieldFilterHeader) {
            $filter->setSearchContext($this->buildSearchContext());
        }

        return $config;
    }

    private function buildSearchContext(): SearchContext
    {
        $context = ApplicationRequest::singleton()->getDefaultSearchContext();
        $fields = $context->getFields();
        $serviceIds = MunicipalService::idsForMember();

        $fields->replaceField('Status', DropdownField::create('Status', 'Status', RequestStatus::options(false))->setEmptyString('— svi —'));
        $fields->replaceField('ServiceID', DropdownField::create(
            'ServiceID',
            'Služba',
            MunicipalService::get()->filter('ID', $serviceIds ?: [0])->map('ID', 'TitleWithCode')
        )->setEmptyString('— sve moje službe —'));
        $fields->replaceField('ProcedureID', DropdownField::create(
            'ProcedureID',
            'Procedura',
            ProcedureDefinition::get()->filter('ServiceID', $serviceIds ?: [0])->map('ID', 'TitleWithCode')
        )->setEmptyString('— sve —'));
        $referentIds = \SilverStripe\Security\Member::get()
            ->filter('Groups.ID', MunicipalService::get()->filter('ID', $serviceIds ?: [0])->relation('Groups')->sort(null)->column('ID') ?: [0])
            ->map('ID', 'Name');
        $fields->replaceField('AssignedToID', DropdownField::create('AssignedToID', 'Obrađuje', $referentIds)->setEmptyString('— svi —'));
        $fields->replaceField('IntakeChannel', DropdownField::create('IntakeChannel', 'Kanal', ['Portal' => 'Portal', 'Counter' => 'Šalter'])->setEmptyString('— svi —'));
        $lights = [];
        foreach (VerificationLight::cases() as $light) {
            $lights[$light->value] = $light->icon() . ' ' . $light->label();
        }
        $fields->replaceField('AiOverallLight', DropdownField::create('AiOverallLight', 'AI semafor', $lights)->setEmptyString('— svi —'));

        return $context;
    }
}
