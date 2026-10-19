<?php

declare(strict_types=1);

use SilverStripe\Admin\CMSMenu;

// "e-Uprava: izvještaji" in the CMS menu – the controller itself checks EGOV_REPORTS.
CMSMenu::add_link('EGovReports', 'e-Uprava: izvještaji', '/egov-izvjestaji', 6, null, 'font-icon-chart-line');
