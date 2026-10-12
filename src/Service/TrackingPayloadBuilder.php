<?php

namespace FullscreenInteractive\ABTesting\Service;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Control\Director;

/**
 * Builds the canonical event payload shared by dataLayer, webhooks, and custom bridges.
 */
class TrackingPayloadBuilder
{
    use Injectable;

    public const EVENT_EXPOSURE = 'exposure';

    public const EVENT_CONVERSION = 'conversion';

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function build(
        string $eventType,
        ABTestExperiment $experiment,
        string $variationKey,
        array $context = []
    ): array {
        $titles = $experiment->getVariationTitleMap();

        return array_merge([
            'event_type' => $eventType,
            'event_id' => bin2hex(random_bytes(8)),
            'timestamp' => gmdate('c'),
            'experiment' => [
                'id' => (int) $experiment->ID,
                'key' => $experiment->Key,
                'title' => $experiment->Title,
                'status' => $experiment->Status,
            ],
            'variation' => [
                'key' => $variationKey,
                'title' => $titles[$variationKey] ?? $variationKey,
                'is_control' => $variationKey === ($experiment->ControlKey ?: 'control'),
            ],
            'page' => [
                'id' => $context['page_id'] ?? null,
                'url' => $context['page_url'] ?? Director::absoluteBaseURL(),
            ],
            'visitor' => [
                'id' => $context['visitor_id'] ?? null,
            ],
        ], array_intersect_key($context, array_flip(['custom'])));
    }
}
