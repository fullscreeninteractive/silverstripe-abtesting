<?php

namespace FullscreenInteractive\ABTesting\Tests\Bridge;

use FullscreenInteractive\ABTesting\Bridge\DataLayerBridge;
use FullscreenInteractive\ABTesting\Service\TrackingPayloadBuilder;
use SilverStripe\Dev\SapphireTest;

class DataLayerBridgeTest extends SapphireTest
{
    protected function setUp(): void
    {
        parent::setUp();
        DataLayerBridge::clearQueued();
    }

    public function testTrackQueuesDataLayerEvent(): void
    {
        $bridge = DataLayerBridge::create();
        $bridge->track([
            'event_type' => TrackingPayloadBuilder::EVENT_EXPOSURE,
            'event_id' => 'evt1',
            'experiment' => [
                'id' => 9,
                'key' => 'hero',
                'title' => 'Hero test',
            ],
            'variation' => [
                'key' => 'b',
                'title' => 'Variant B',
                'is_control' => false,
            ],
        ]);

        $queued = DataLayerBridge::getQueued();
        $this->assertCount(1, $queued);
        $this->assertSame('ab_test_exposure', $queued[0]['event']);
        $this->assertSame('hero', $queued[0]['ab_experiment_key']);
        $this->assertSame('b', $queued[0]['ab_variation']);
    }
}
