<?php

namespace FullscreenInteractive\ABTesting\Bridge;

use FullscreenInteractive\ABTesting\Service\TrackingPayloadBuilder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\View\Requirements;

/**
 * Queues Google dataLayer pushes for the current response.
 */
class DataLayerBridge implements TrackingBridgeInterface
{
    use Configurable;
    use Injectable;

    private static string $event_exposure = 'ab_test_exposure';

    private static string $event_conversion = 'ab_test_conversion';

    /**
     * @var list<array<string, mixed>>
     */
    private static array $queued = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function track(array $payload): void
    {
        $experiment = $payload['experiment'] ?? [];
        $isConversion = ($payload['event_type'] ?? '') === TrackingPayloadBuilder::EVENT_CONVERSION;
        $eventName = $isConversion
            ? static::config()->get('event_conversion')
            : static::config()->get('event_exposure');

        self::$queued[] = [
            'event' => $eventName,
            'ab_experiment_id' => $experiment['id'] ?? null,
            'ab_experiment_key' => $experiment['key'] ?? null,
            'ab_experiment_name' => $experiment['title'] ?? null,
            'ab_variation' => $payload['variation']['key'] ?? null,
            'ab_variation_name' => $payload['variation']['title'] ?? null,
            'ab_is_control' => $payload['variation']['is_control'] ?? false,
            'ab_event_id' => $payload['event_id'] ?? null,
        ];
    }

    public function flushToRequirements(): void
    {
        if (!self::$queued) {
            return;
        }

        $json = json_encode(self::$queued, JSON_UNESCAPED_SLASHES);
        self::$queued = [];

        Requirements::customScript(
            <<<JS
window.dataLayer = window.dataLayer || [];
(function (events) {
    for (var i = 0; i < events.length; i++) {
        window.dataLayer.push(events[i]);
    }
})($json);
JS,
            'abtesting-datalayer'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getQueued(): array
    {
        return self::$queued;
    }

    public static function clearQueued(): void
    {
        self::$queued = [];
    }
}
