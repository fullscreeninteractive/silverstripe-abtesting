<?php

namespace FullscreenInteractive\ABTesting\Tests\Service;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use FullscreenInteractive\ABTesting\Model\ABTestVariation;
use FullscreenInteractive\ABTesting\Service\VariationAssigner;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;

class VariationAssignerTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testForcedVariationFromQueryString(): void
    {
        $experiment = $this->createExperiment();
        $assigner = VariationAssigner::create($experiment);
        $request = $this->makeRequest(['abv' => 'variant-b']);

        $this->assertSame('variant-b', $assigner->getForcedVariation($request));
        $this->assertSame('variant-b', $assigner->determine($request));
    }

    public function testInvalidForcedVariationIsIgnored(): void
    {
        $experiment = $this->createExperiment();
        $assigner = VariationAssigner::create($experiment);
        $request = $this->makeRequest(['abv' => 'does-not-exist']);

        $this->assertNull($assigner->getForcedVariation($request));
    }

    public function testWeightedChoiceReturnsKnownKeys(): void
    {
        $experiment = $this->createExperiment();
        $assigner = VariationAssigner::create($experiment);
        $keys = array_keys($experiment->getVariationWeights());

        for ($i = 0; $i < 20; $i++) {
            $this->assertContains($assigner->chooseWeightedVariation(), $keys);
        }
    }

    public function testZeroTrafficDoesNotEnrol(): void
    {
        $experiment = $this->createExperiment(['TrafficPercent' => 0]);
        $assigner = VariationAssigner::create($experiment);

        $this->assertFalse($assigner->shouldEnrol());
        $this->assertNull($assigner->determine($this->makeRequest()));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createExperiment(array $extra = []): ABTestExperiment
    {
        $experiment = ABTestExperiment::create(array_merge([
            'Title' => 'Homepage CTA',
            'Key' => 'homepage-cta-' . uniqid(),
            'Status' => 'Active',
            'TestedPageID' => 1,
            'ControlKey' => 'control',
            'ControlWeight' => 50,
            'TrafficPercent' => 100,
            'VariationQueryVariable' => 'abv',
            'StateMechanism' => 'Session',
        ], $extra));
        $experiment->write(false, false, true);

        $variation = ABTestVariation::create([
            'Title' => 'Variant B',
            'VariationKey' => 'variant-b',
            'Weight' => 50,
            'ExperimentID' => $experiment->ID,
        ]);
        $variation->write();

        return $experiment;
    }

    /**
     * @param array<string, string> $get
     */
    private function makeRequest(array $get = []): HTTPRequest
    {
        $request = new HTTPRequest('GET', '/', $get);
        $request->setSession(new Session([]));

        return $request;
    }
}
