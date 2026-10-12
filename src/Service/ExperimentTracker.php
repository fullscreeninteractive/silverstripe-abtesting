<?php

namespace FullscreenInteractive\ABTesting\Service;

use FullscreenInteractive\ABTesting\Model\ABTestData;
use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use FullscreenInteractive\ABTesting\Model\ABTestVariation;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Records exposures and conversions in the CMS database.
 */
class ExperimentTracker
{
    use Injectable;

    public function hitExposure(
        ABTestExperiment $experiment,
        string $variationKey,
        HTTPRequest $request
    ): ABTestData {
        $visitorId = $this->resolveVisitorId($experiment, $request);
        $existing = ABTestData::get()->filter([
            'ExperimentID' => $experiment->ID,
            'VisitorID' => $visitorId,
        ])->first();

        if ($existing) {
            return $existing;
        }

        $data = ABTestData::create();
        $data->ExperimentID = $experiment->ID;
        $data->VisitorID = $visitorId;
        $data->PHPSessionID = $visitorId;
        $data->VariationKey = $variationKey;
        $data->StateVariableValue = $variationKey;
        $data->WhenRendered = DBDatetime::now()->Rfc2822();
        $data->UserAgent = substr((string) $request->getHeader('User-Agent'), 0, 255);
        $data->write();

        return $data;
    }

    public function hitConversion(
        ABTestExperiment $experiment,
        HTTPRequest $request,
        int $assertiveness = 100
    ): ?ABTestData {
        $visitorId = $this->resolveVisitorId($experiment, $request);
        /** @var ABTestData|null $data */
        $data = ABTestData::get()->filter([
            'ExperimentID' => $experiment->ID,
            'VisitorID' => $visitorId,
        ])->first();

        if (!$data) {
            return null;
        }

        $data->markConverted($assertiveness);

        return $data;
    }

    /**
     * Record conversions for every active experiment that uses this page
     * (experiment-level or variation-level conversion page).
     *
     * @return list<ABTestData>
     */
    public function hitConversionPage($page, HTTPRequest $request): array
    {
        $converted = [];
        $seen = [];

        foreach (ABTestExperiment::getActiveForConversionPage($page) as $experiment) {
            $data = $this->hitConversion($experiment, $request);
            if ($data) {
                $converted[] = $data;
                $seen[$experiment->ID] = true;
            }
        }

        $variations = ABTestVariation::get()->filter([
            'ConversionPageID' => $page->ID,
        ]);

        foreach ($variations as $variation) {
            $experiment = $variation->Experiment();
            if (!$experiment || !$experiment->isRunning() || isset($seen[$experiment->ID])) {
                continue;
            }

            $assigner = VariationAssigner::create($experiment);
            $current = $assigner->getStoredValue($request);
            if ($current !== $variation->VariationKey) {
                continue;
            }

            $data = $this->hitConversion($experiment, $request);
            if ($data) {
                $converted[] = $data;
                $seen[$experiment->ID] = true;
            }
        }

        return $converted;
    }

    public function resolveVisitorId(ABTestExperiment $experiment, HTTPRequest $request): string
    {
        $assigner = VariationAssigner::create($experiment);
        $cookieName = $experiment->getResolvedCookieName() . '_vid';
        $existing = $request->getSession()->get('ABTesting.VisitorID');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $fromCookie = \SilverStripe\Control\Cookie::get($cookieName);
        if (is_string($fromCookie) && $fromCookie !== '') {
            $request->getSession()->set('ABTesting.VisitorID', $fromCookie);

            return $fromCookie;
        }

        $visitorId = bin2hex(random_bytes(16));
        $request->getSession()->set('ABTesting.VisitorID', $visitorId);
        \SilverStripe\Control\Cookie::set($cookieName, $visitorId, 90, null, null, false, false);

        return $visitorId;
    }
}
