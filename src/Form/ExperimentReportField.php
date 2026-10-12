<?php

namespace FullscreenInteractive\ABTesting\Form;

use FullscreenInteractive\ABTesting\Model\ABTestData;
use FullscreenInteractive\ABTesting\Model\ABTestExperiment;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DB;
use SilverStripe\View\Requirements;

/**
 * Renders Chart.js summaries for an experiment inside the CMS.
 */
class ExperimentReportField extends FormField
{
    protected $schemaComponent = 'ExperimentReportField';

    public function __construct(string $name, ?string $title = null, ?ABTestExperiment $experiment = null)
    {
        parent::__construct($name, $title, $experiment?->ID);
        $this->setExperiment($experiment);
    }

    private ?ABTestExperiment $experiment = null;

    public function setExperiment(?ABTestExperiment $experiment): self
    {
        $this->experiment = $experiment;
        $this->setValue($experiment?->ID);

        return $this;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Silverstripe FormField API
    public function Field($properties = [])
    {
        Requirements::javascript('https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js');
        Requirements::javascript('fullscreeninteractive/silverstripe-abtesting:client/dist/js/admin-reports.js');
        Requirements::css('fullscreeninteractive/silverstripe-abtesting:client/dist/css/abtesting.css');

        $experiment = $this->experiment;
        if (!$experiment || !$experiment->exists()) {
            return '<p class="abtesting-empty">Save the experiment to view results.</p>';
        }

        $summary = $this->buildSummary($experiment);
        $chartId = 'abtesting-chart-' . $this->getName() . '-' . $experiment->ID;
        $chartPayload = json_encode($this->buildChartPayload($experiment), JSON_UNESCAPED_SLASHES);

        $rows = '';
        foreach ($summary as $row) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%.1f%%</td></tr>',
                htmlspecialchars((string) $row['title'], ENT_QUOTES),
                $row['exposures'],
                $row['conversions'],
                $row['rate']
            );
        }

        if ($this->getName() === 'Summary') {
            return <<<HTML
<div class="abtesting-report">
  <table class="abtesting-summary-table">
    <thead>
      <tr><th>Variation</th><th>Exposures</th><th>Conversions</th><th>Rate</th></tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
        }

        return <<<HTML
<div class="abtesting-report">
  <canvas id="{$chartId}" class="abtesting-chart" height="160"
    data-abtesting-chart='{$chartPayload}'></canvas>
</div>
HTML;
    }

    /**
     * @return list<array{key:string,title:string,exposures:int,conversions:int,rate:float}>
     */
    private function buildSummary(ABTestExperiment $experiment): array
    {
        $rows = [];
        foreach ($experiment->getVariationTitleMap() as $key => $title) {
            $exposures = ABTestData::get()->filter([
                'ExperimentID' => $experiment->ID,
                'VariationKey' => $key,
            ])->count();

            $conversions = ABTestData::get()->filter([
                'ExperimentID' => $experiment->ID,
                'VariationKey' => $key,
            ])->where('"WhenConverted" IS NOT NULL')->count();

            $rows[] = [
                'key' => $key,
                'title' => $title,
                'exposures' => $exposures,
                'conversions' => $conversions,
                'rate' => $exposures > 0 ? ($conversions / $exposures) * 100 : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChartPayload(ABTestExperiment $experiment): array
    {
        $datasets = [];
        $labels = [];

        foreach ($experiment->getVariationTitleMap() as $key => $title) {
            $sql = 'SELECT DATE("WhenRendered") AS DayRendered, COUNT("ID") AS Exposures,
                SUM(CASE WHEN "WhenConverted" IS NOT NULL THEN 1 ELSE 0 END) AS Conversions
                FROM "ABTestData"
                WHERE "ExperimentID" = ? AND "VariationKey" = ?
                GROUP BY DATE("WhenRendered")
                ORDER BY DATE("WhenRendered")';

            $result = DB::prepared_query($sql, [$experiment->ID, $key]);
            $points = [];

            foreach ($result as $row) {
                $day = (string) $row['DayRendered'];
                if (!in_array($day, $labels, true)) {
                    $labels[] = $day;
                }

                $value = $this->getName() === 'ConversionRate'
                    ? (($row['Exposures'] > 0) ? ((float) $row['Conversions'] / (float) $row['Exposures']) * 100 : 0)
                    : (float) $row['Exposures'];

                $points[$day] = $value;
            }

            $datasets[] = [
                'label' => $key . ': ' . $title,
                'points' => $points,
            ];
        }

        sort($labels);

        $chartDatasets = [];
        foreach ($datasets as $dataset) {
            $data = [];
            foreach ($labels as $label) {
                $data[] = $dataset['points'][$label] ?? 0;
            }
            $chartDatasets[] = [
                'label' => $dataset['label'],
                'data' => $data,
                'tension' => 0.25,
            ];
        }

        return [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => $chartDatasets,
            ],
            'options' => [
                'responsive' => true,
                'plugins' => [
                    'legend' => ['position' => 'bottom'],
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'title' => [
                            'display' => true,
                            'text' => $this->getName() === 'ConversionRate' ? 'Conversion %' : 'Exposures',
                        ],
                    ],
                ],
            ],
        ];
    }
}
