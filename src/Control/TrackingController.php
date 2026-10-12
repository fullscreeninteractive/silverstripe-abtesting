<?php

namespace FullscreenInteractive\ABTesting\Control;

use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use FullscreenInteractive\ABTesting\Service\ExperimentTracker;
use FullscreenInteractive\ABTesting\Service\TrackingService;
use FullscreenInteractive\ABTesting\Service\VariationAssigner;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;

/**
 * Public JSON API for custom conversion points and third-party integrations.
 *
 * Routes (see _config/routes.yml):
 * - POST /abtesting/convert           body: { experiment: "key"|id, assertiveness?: 0-100 }
 * - GET  /abtesting/assignment        ?experiment=key
 * - GET  /abtesting/event-schema      documentation helper
 */
class TrackingController extends Controller
{
    private static array $allowed_actions = [
        'index',
        'convert',
        'assignment',
        'eventschema',
    ];

    public function index(HTTPRequest $request): HTTPResponse
    {
        return $this->json([
            'module' => 'fullscreeninteractive/silverstripe-abtesting',
            'endpoints' => [
                'POST /abtesting/convert' => 'Record a conversion for the current visitor',
                'GET /abtesting/assignment' => 'Read the current visitor assignment',
                'GET /abtesting/eventschema' => 'Canonical event payload schema',
            ],
        ]);
    }

    public function convert(HTTPRequest $request): HTTPResponse
    {
        $this->assertPost($request);

        $payload = $this->decodeJsonBody($request);
        $identifier = $payload['experiment']
            ?? $request->postVar('experiment')
            ?? $request->getVar('experiment');
        $experiment = $this->findExperiment($identifier);

        if (!$experiment || !$experiment->isRunning()) {
            return $this->json(['error' => 'Active experiment not found'], 404);
        }

        $assigner = VariationAssigner::create($experiment);
        $variationKey = $assigner->getStoredValue($request);
        if (!$variationKey || $variationKey === VariationAssigner::NOT_ENROLLED) {
            return $this->json(['error' => 'Visitor is not enrolled in this experiment'], 400);
        }

        $assertiveness = (int) ($payload['assertiveness'] ?? $request->postVar('assertiveness') ?? 100);
        $tracker = ExperimentTracker::create();
        $data = $tracker->hitConversion($experiment, $request, $assertiveness);

        if (!$data) {
            return $this->json(['error' => 'No exposure found for visitor'], 400);
        }

        $event = TrackingService::create()->trackConversion($experiment, (string) $data->VariationKey, [
            'visitor_id' => $data->VisitorID,
            'page_url' => $request->getHeader('Referer') ?: null,
            'custom' => $payload['custom'] ?? null,
        ]);

        return $this->json([
            'ok' => true,
            'converted' => true,
            'event' => $event,
        ]);
    }

    public function assignment(HTTPRequest $request): HTTPResponse
    {
        $experiment = $this->findExperiment($request->getVar('experiment'));
        if (!$experiment) {
            return $this->json(['error' => 'Experiment not found'], 404);
        }

        $assigner = VariationAssigner::create($experiment);
        $value = $assigner->getStoredValue($request);

        return $this->json([
            'experiment' => $experiment->Key,
            'enrolled' => $value !== null && $value !== VariationAssigner::NOT_ENROLLED,
            'variation' => ($value === VariationAssigner::NOT_ENROLLED) ? null : $value,
        ]);
    }

    public function eventschema(HTTPRequest $request): HTTPResponse
    {
        return $this->json([
            'event_type' => 'exposure|conversion',
            'event_id' => 'string',
            'timestamp' => 'ISO-8601',
            'experiment' => [
                'id' => 'int',
                'key' => 'string',
                'title' => 'string',
                'status' => 'Draft|Active|Paused|Complete',
            ],
            'variation' => [
                'key' => 'string',
                'title' => 'string',
                'is_control' => 'bool',
            ],
            'page' => [
                'id' => 'int|null',
                'url' => 'string|null',
            ],
            'visitor' => [
                'id' => 'string|null',
            ],
            'custom' => 'object|null',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($data, JSON_UNESCAPED_SLASHES), $status);
        $response->addHeader('Content-Type', 'application/json');
        $response->addHeader('Cache-Control', 'no-store');

        return $response;
    }

    private function assertPost(HTTPRequest $request): void
    {
        if (!$request->isPOST()) {
            throw new HTTPResponse_Exception('Method not allowed', 405);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(HTTPRequest $request): array
    {
        $body = $request->getBody();
        if (!$body) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function findExperiment(mixed $identifier): ?ABTestExperiment
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        if (is_numeric($identifier)) {
            return ABTestExperiment::get()->byID((int) $identifier);
        }

        return ABTestExperiment::get()->find('Key', (string) $identifier);
    }
}
