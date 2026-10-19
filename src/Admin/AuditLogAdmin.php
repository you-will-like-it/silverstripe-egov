<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;

/**
 * Read-only access trail ("e-Uprava: revizija").
 */
class AuditLogAdmin extends ModelAdmin
{
    private static string $url_segment = 'e-uprava-revizija';

    private static string $menu_title = 'e-Uprava: revizija';

    private static string $menu_icon_class = 'font-icon-eye';

    private static int $menu_priority = 7;

    private static array $managed_models = [
        'audit' => [
            'dataClass' => AuditLogEntry::class,
            'title' => 'Revizijski trag',
        ],
    ];

    private static array $required_permission_codes = [ApplicationRequest::PERMISSION_AUDIT];

    private static int $page_length = 50;

    public $showImportForm = false;

    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->removeComponentsByType([GridFieldAddNewButton::class, GridFieldDeleteAction::class, GridFieldEditButton::class]);
        return $config;
    }
}
