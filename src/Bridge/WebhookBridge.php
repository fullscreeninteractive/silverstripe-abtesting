<?php

namespace FullscreenInteractive\ABTesting\Bridge;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * POSTs experiment event payloads to configured HTTP endpoints.
 *
 * Endpoints come from:
 * - YAML config (`endpoints`)
 * - Per-experiment `WebhookURL` when present in payload context
 */
class WebhookBridge implements TrackingBridgeInterface
{
    use Configurable;
    use Injectable;

    /**
     * @var list<string>
     */
    private static array $endpoints = [];

    private static int $timeout = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function track(array $payload): void
    {
        $endpoints = static::config()->get('endpoints') ?: [];
        if (!is_array($endpoints)) {
            $endpoints = [];
        }

        if (!empty($payload['webhook_url']) && is_string($payload['webhook_url'])) {
            $endpoints[] = $payload['webhook_url'];
        }

        $endpoints = array_values(array_unique(array_filter($endpoints)));
        if (!$endpoints) {
            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timeout = (int) static::config()->get('timeout') ?: 5;

        foreach ($endpoints as $endpoint) {
            $this->postJson((string) $endpoint, (string) $body, $timeout);
        }
    }

    private function postJson(string $url, string $body, int $timeout): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'http')) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            $logger = Injector::inst()->get(LoggerInterface::class);
            $logger->warning('ABTesting webhook failed', ['url' => $url]);
        }
    }
}
