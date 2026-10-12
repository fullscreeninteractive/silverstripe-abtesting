<?php

namespace FullscreenInteractive\ABTesting\Service;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use SilverStripe\Control\Cookie;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;

/**
 * Assigns and persists a visitor's variation for an experiment.
 */
class VariationAssigner
{
    use Injectable;

    public const NOT_ENROLLED = '__not_enrolled__';

    public function __construct(
        private readonly ABTestExperiment $experiment
    ) {
    }

    public function determine(HTTPRequest $request): ?string
    {
        $existing = $this->getStoredValue($request);

        if ($existing === self::NOT_ENROLLED) {
            return null;
        }

        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $forced = $this->getForcedVariation($request);
        if ($forced !== null) {
            $this->setStoredValue($request, $forced);

            return $forced;
        }

        if (!$this->shouldEnrol()) {
            $this->setStoredValue($request, self::NOT_ENROLLED);

            return null;
        }

        $chosen = $this->chooseWeightedVariation();
        $this->setStoredValue($request, $chosen);

        return $chosen;
    }

    public function getStoredValue(HTTPRequest $request): ?string
    {
        return match ($this->experiment->StateMechanism) {
            'Session' => $request->getSession()->get($this->sessionKey()),
            default => Cookie::get($this->experiment->getResolvedCookieName()),
        };
    }

    public function setStoredValue(HTTPRequest $request, string $value): void
    {
        match ($this->experiment->StateMechanism) {
            'Session' => $request->getSession()->set($this->sessionKey(), $value),
            default => Cookie::set(
                $this->experiment->getResolvedCookieName(),
                $value,
                90,
                null,
                null,
                false,
                false
            ),
        };
    }

    public function getForcedVariation(HTTPRequest $request): ?string
    {
        $param = $this->experiment->VariationQueryVariable;
        if (!$param) {
            return null;
        }

        $value = $request->getVar($param);
        if (!is_string($value) || $value === '') {
            return null;
        }

        $map = $this->experiment->getVariationTitleMap();

        return array_key_exists($value, $map) ? $value : null;
    }

    public function shouldEnrol(): bool
    {
        $percent = (int) $this->experiment->TrafficPercent;
        if ($percent >= 100) {
            return true;
        }

        if ($percent <= 0) {
            return false;
        }

        return random_int(1, 100) <= $percent;
    }

    public function chooseWeightedVariation(): string
    {
        $weights = $this->experiment->getVariationWeights();
        $total = array_sum($weights);

        if ($total <= 0) {
            return $this->experiment->ControlKey ?: 'control';
        }

        $pick = random_int(1, $total);
        $running = 0;

        foreach ($weights as $key => $weight) {
            $running += $weight;
            if ($pick <= $running) {
                return (string) $key;
            }
        }

        return $this->experiment->ControlKey ?: 'control';
    }

    private function sessionKey(): string
    {
        return 'ABTesting.Experiment.' . $this->experiment->ID;
    }
}
