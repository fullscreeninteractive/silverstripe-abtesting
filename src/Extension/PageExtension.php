<?php

namespace FullscreenInteractive\ABTesting\Extension;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataList;

/**
 * Adds an A/B Testing tab on pages that participate in experiments.
 *
 * @extends Extension<\Page>
 */
class PageExtension extends Extension
{
    public function updateCMSFields(FieldList $fields): void
    {
        $page = $this->owner;
        if (!$page->isInDB()) {
            return;
        }

        $experiments = $this->getRelatedExperiments();
        if (!$experiments->exists()) {
            $fields->addFieldsToTab('Root.ABTesting', [
                HeaderField::create('ABTestingHeader', 'A/B Testing'),
                LiteralField::create(
                    'ABTestingEmpty',
                    '<p>This page is not used by any experiments. Create one under '
                    . '<strong>A/B Testing</strong> in the main menu.</p>'
                ),
            ]);

            return;
        }

        $fields->addFieldToTab(
            'Root.ABTesting',
            GridField::create(
                'ABTestingExperiments',
                'Related experiments',
                $experiments,
                GridFieldConfig_RecordViewer::create()
            )
        );
    }

    /**
     * @return DataList<ABTestExperiment>
     */
    private function getRelatedExperiments(): DataList
    {
        $pageId = (int) $this->owner->ID;

        return ABTestExperiment::get()->filterAny([
            'TestedPageID' => $pageId,
            'ConversionPageID' => $pageId,
            'Variations.AlternatePageID' => $pageId,
            'Variations.ConversionPageID' => $pageId,
        ]);
    }
}
