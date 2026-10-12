<?php

namespace FullscreenInteractive\ABTesting\Service;

use FullscreenInteractive\ABTesting\Bridge\DataLayerBridge;
use FullscreenInteractive\ABTesting\Bridge\TrackingBridgeInterface;
use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Dispatches experiment events through configured tracking bridges.
 *
 * Projects can register additional bridges via Injector / YAML:
 *
 * FullscreenInteractive\ABTesting\Service\TrackingService:
 *   bridges:
 *     - '%$My\App\SegmentBridge'
 */
class TrackingService
{
    use Configurable;
    use Injectable;

    /**
     * @var list<string|TrackingBridgeInterface>
     */
    private static array $bridges = [];

    /**
     * @param array<string, mixed> $context
     */
    public function trackExposure(
        ABTestExperiment $experiment,
        string $variationKey,
        array $context = []
    ): array {
        return $this->dispatch(
            TrackingPayloadBuilder::EVENT_EXPOSURE,
            $experiment,
            $variationKey,
            $context
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function trackConversion(
        ABTestExperiment $experiment,
        string $variationKey,
        array $context = []
    ): array {
        return $this->dispatch(
            TrackingPayloadBuilder::EVENT_CONVERSION,
            $experiment,
            $variationKey,
            $context
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function dispatch(
        string $eventType,
        ABTestExperiment $experiment,
        string $variationKey,
        array $context
    ): array {
        if ($experiment->WebhookURL) {
            $context['webhook_url'] = $experiment->WebhookURL;
        }

        $payload = TrackingPayloadBuilder::create()->build($eventType, $experiment, $variationKey, $context);

        if ($experiment->WebhookURL) {
            $payload['webhook_url'] = $experiment->WebhookURL;
        }

        foreach ($this->getBridges($experiment) as $bridge) {
            $bridge->track($payload);
        }

        return $payload;
    }

    /**
     * @return list<TrackingBridgeInterface>
     */
    private function getBridges(ABTestExperiment $experiment): array
    {
        $configured = static::config()->get('bridges') ?: [];
        $bridges = [];

        foreach ($configured as $bridge) {
            if (is_string($bridge)) {
                $bridge = Injector::inst()->get($bridge);
            }

            if (!$bridge instanceof TrackingBridgeInterface) {
                continue;
            }

            if ($bridge instanceof DataLayerBridge && !$experiment->EnableDataLayer) {
                continue;
            }

            $bridges[] = $bridge;
        }

        return $bridges;
    }

    public function flushFrontend(): void
    {
        /** @var DataLayerBridge $dataLayer */
        $dataLayer = Injector::inst()->get(DataLayerBridge::class);
        $dataLayer->flushToRequirements();
    }
}
