<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Admin;

use SilverStripe\Admin\ModelAdmin;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\MunicipalService;
use YouWillLikeIT\EGov\Model\NotificationTemplate;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;

/**
 * "e-Uprava: postavke" – services, procedures, fees and required documents.
 * Municipality parameters, accounts and k.o. live in Settings → e-Uprava.
 */
class EGovSettingsAdmin extends ModelAdmin
{
    private static string $url_segment = 'e-uprava-postavke';

    private static string $menu_title = 'e-Uprava: postavke';

    private static string $menu_icon_class = 'font-icon-cog';

    private static int $menu_priority = 8;

    private static array $managed_models = [
        'procedures' => [
            'dataClass' => ProcedureDefinition::class,
            'title' => 'Procedure',
        ],
        'services' => [
            'dataClass' => MunicipalService::class,
            'title' => 'Službe',
        ],
        'notifications' => [
            'dataClass' => NotificationTemplate::class,
            'title' => 'Tekstovi obavještenja',
        ],
    ];

    private static array $required_permission_codes = [ApplicationRequest::PERMISSION_CONFIG];

    public $showImportForm = false;
}
