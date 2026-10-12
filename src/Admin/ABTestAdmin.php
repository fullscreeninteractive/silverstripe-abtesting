<?php

namespace FullscreenInteractive\ABTesting\Admin;

use FullscreenInteractive\ABTesting\Model\ABTestData;
use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use SilverStripe\Admin\ModelAdmin;

class ABTestAdmin extends ModelAdmin
{
    private static string $url_segment = 'abtesting';

    private static string $menu_title = 'A/B Testing';

    private static string $menu_icon_class = 'font-icon-chart-line';

    private static int $menu_priority = 6;

    private static array $managed_models = [
        ABTestExperiment::class => ['title' => 'Experiments'],
        ABTestData::class => ['title' => 'Raw data'],
    ];
}
