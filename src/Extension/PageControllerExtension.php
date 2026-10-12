<?php

namespace FullscreenInteractive\ABTesting\Extension;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use FullscreenInteractive\ABTesting\Service\ExperimentTracker;
use FullscreenInteractive\ABTesting\Service\TrackingService;
use FullscreenInteractive\ABTesting\Service\VariationAssigner;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * Applies active experiments during page requests and records conversions.
 *
 * @extends Extension<\PageController>
 */
class PageControllerExtension extends Extension
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $activeAssignment = null;

    public function onBeforeInit(): void
    {
        /** @var \PageController $controller */
        $controller = $this->owner;
        $page = $controller->data();
        if (!$page instanceof SiteTree) {
            return;
        }

        $request = $controller->getRequest();
        $tracker = ExperimentTracker::create();
        $tracking = TrackingService::create();

        $experiment = ABTestExperiment::getActiveForPage($page);
        if ($experiment) {
            $this->applyExperiment($experiment, $page, $request, $tracker, $tracking);
        }

        $converted = $tracker->hitConversionPage($page, $request);
        foreach ($converted as $data) {
            $experiment = $data->Experiment();
            if (!$experiment) {
                continue;
            }

            $tracking->trackConversion($experiment, (string) $data->VariationKey, [
                'page_id' => $page->ID,
                'page_url' => $page->AbsoluteLink(),
                'visitor_id' => $data->VisitorID,
            ]);
        }

        $tracking->flushFrontend();
        $this->includeFrontendHelpers();
    }

    private function applyExperiment(
        ABTestExperiment $experiment,
        SiteTree $page,
        HTTPRequest $request,
        ExperimentTracker $tracker,
        TrackingService $tracking
    ): void {
        $assigner = VariationAssigner::create($experiment);
        $variationKey = $assigner->determine($request);

        if ($variationKey === null) {
            return;
        }

        $data = $tracker->hitExposure($experiment, $variationKey, $request);
        $variation = $experiment->getVariationByKey($variationKey);

        if ($variation && $variation->AlternatePageID) {
            $this->copyAlternateContent($page, $variation->AlternatePage());
        }

        if ($variation && $variation->Presentation === 'AlternateTemplate' && $variation->AlternateTemplate) {
            $templates = array_values(array_filter(array_map('trim', explode(',', $variation->AlternateTemplate))));
            if (count($templates) === 1) {
                $templates[] = 'Page';
            }
            $this->owner->templates['index'] = $templates;
        }

        $this->activeAssignment = [
            'experiment_id' => (int) $experiment->ID,
            'experiment_key' => $experiment->Key,
            'experiment_title' => $experiment->Title,
            'variation_key' => $variationKey,
            'variation_title' => $experiment->getVariationTitleMap()[$variationKey] ?? $variationKey,
            'is_control' => $variationKey === ($experiment->ControlKey ?: 'control'),
            'visitor_id' => $data->VisitorID,
        ];

        $tracking->trackExposure($experiment, $variationKey, [
            'page_id' => $page->ID,
            'page_url' => $page->AbsoluteLink(),
            'visitor_id' => $data->VisitorID,
        ]);

        $json = json_encode($this->activeAssignment, JSON_UNESCAPED_SLASHES);
        Requirements::customScript(
            "window.SSABTesting = window.SSABTesting || {}; window.SSABTesting.assignment = {$json};",
            'abtesting-assignment'
        );
    }

    private function copyAlternateContent(SiteTree $page, ?SiteTree $alternate): void
    {
        if (!$alternate || !$alternate->exists()) {
            return;
        }

        $map = [];
        foreach ($alternate->toMap() as $key => $value) {
            if (
                str_contains((string) $key, '.')
                || in_array($key, ['ID', 'URLSegment', 'ParentID', 'ClassName', 'RecordClassName'], true)
            ) {
                continue;
            }
            $map[$key] = $value;
        }

        $page->update($map);
    }

    private function includeFrontendHelpers(): void
    {
        Requirements::javascript('fullscreeninteractive/silverstripe-abtesting:client/dist/js/abtesting.js');
    }

    /**
     * Template helper: {$ABTestAssignment.variation_key}
     *
     * @return array<string, mixed>|null
     */
    public function ABTestAssignment(): ?array
    {
        return $this->activeAssignment;
    }
}
