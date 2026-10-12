<?php

namespace FullscreenInteractive\ABTesting\Tests\Model;

use FullscreenInteractive\ABTesting\Model\ABTestData;
use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use FullscreenInteractive\ABTesting\Model\ABTestVariation;
use FullscreenInteractive\ABTesting\Service\ExperimentTracker;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;

class ABTestExperimentTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testKeyIsGeneratedFromTitle(): void
    {
        $experiment = ABTestExperiment::create([
            'Title' => 'Homepage Hero CTA',
            'Status' => 'Draft',
        ]);
        $experiment->write();

        $this->assertSame('homepage-hero-cta', $experiment->Key);
    }

    public function testVariationKeyUniquenessIsValidated(): void
    {
        $experiment = ABTestExperiment::create([
            'Title' => 'Uniqueness',
            'Key' => 'uniqueness',
            'Status' => 'Draft',
            'ControlKey' => 'control',
        ]);
        $experiment->write();

        $first = ABTestVariation::create([
            'Title' => 'A',
            'VariationKey' => 'variant-a',
            'ExperimentID' => $experiment->ID,
        ]);
        $first->write();

        $duplicate = ABTestVariation::create([
            'Title' => 'A copy',
            'VariationKey' => 'variant-a',
            'ExperimentID' => $experiment->ID,
        ]);

        $result = $duplicate->validate();
        $this->assertFalse($result->isValid());
    }

    public function testExposureAndConversionAreRecordedOnce(): void
    {
        $experiment = ABTestExperiment::create([
            'Title' => 'Funnel',
            'Key' => 'funnel',
            'Status' => 'Active',
            'TestedPageID' => 1,
            'ConversionPageID' => 2,
            'StateMechanism' => 'Session',
            'TrafficPercent' => 100,
        ]);
        // Skip validation so tests can use placeholder page IDs without SiteTree fixtures.
        $experiment->write(false, false, true);

        $request = new HTTPRequest('GET', '/tested');
        $request->setSession(new Session([]));

        $tracker = ExperimentTracker::create();
        $first = $tracker->hitExposure($experiment, 'control', $request);
        $second = $tracker->hitExposure($experiment, 'control', $request);

        $this->assertSame($first->ID, $second->ID);
        $this->assertSame(1, ABTestData::get()->filter('ExperimentID', $experiment->ID)->count());

        $converted = $tracker->hitConversion($experiment, $request);
        $this->assertNotNull($converted);
        $this->assertTrue($converted->isConverted());

        // Second conversion is a no-op.
        $tracker->hitConversion($experiment, $request);
        $this->assertSame(1, ABTestData::get()->filter([
            'ExperimentID' => $experiment->ID,
        ])->where('"WhenConverted" IS NOT NULL')->count());
    }

    public function testGetVariationWeightsIncludesControlAndVariants(): void
    {
        $experiment = ABTestExperiment::create([
            'Title' => 'Weights',
            'Key' => 'weights',
            'Status' => 'Draft',
            'ControlKey' => 'control',
            'ControlWeight' => 70,
        ]);
        $experiment->write();

        ABTestVariation::create([
            'Title' => 'B',
            'VariationKey' => 'b',
            'Weight' => 30,
            'ExperimentID' => $experiment->ID,
        ])->write();

        $this->assertSame([
            'control' => 70,
            'b' => 30,
        ], $experiment->getVariationWeights());
    }
}
