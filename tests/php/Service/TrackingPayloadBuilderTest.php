<?php

namespace FullscreenInteractive\ABTesting\Tests\Service;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use FullscreenInteractive\ABTesting\Service\TrackingPayloadBuilder;
use SilverStripe\Dev\SapphireTest;

class TrackingPayloadBuilderTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testBuildExposurePayload(): void
    {
        $experiment = ABTestExperiment::create([
            'Title' => 'Pricing page',
            'Key' => 'pricing-page',
            'Status' => 'Draft',
            'ControlKey' => 'control',
            'ControlTitle' => 'Control',
        ]);
        $experiment->write();

        $payload = TrackingPayloadBuilder::create()->build(
            TrackingPayloadBuilder::EVENT_EXPOSURE,
            $experiment,
            'control',
            [
                'page_id' => 12,
                'page_url' => 'https://example.org/pricing',
                'visitor_id' => 'abc123',
            ]
        );

        $this->assertSame('exposure', $payload['event_type']);
        $this->assertSame('pricing-page', $payload['experiment']['key']);
        $this->assertSame('control', $payload['variation']['key']);
        $this->assertTrue($payload['variation']['is_control']);
        $this->assertSame(12, $payload['page']['id']);
        $this->assertSame('abc123', $payload['visitor']['id']);
        $this->assertNotEmpty($payload['event_id']);
        $this->assertNotEmpty($payload['timestamp']);
    }
}
