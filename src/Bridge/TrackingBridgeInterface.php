<?php

namespace FullscreenInteractive\ABTesting\Bridge;

/**
 * Contract for delivering experiment events to an analytics or integration party.
 */
interface TrackingBridgeInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function track(array $payload): void;
}
