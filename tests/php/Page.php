<?php

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

// silverstripe/cms expects the consuming project to define top level "Page"
// and "PageController" classes. Provide minimal stand-ins for the test run.
if (!class_exists('Page') && class_exists(SiteTree::class)) {
    class Page extends SiteTree implements TestOnly
    {
        private static $table_name = 'ABTesting_Page';
    }
}

if (!class_exists('PageController') && class_exists(ContentController::class)) {
    class PageController extends ContentController implements TestOnly
    {
    }
}
