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

        if ($this->getName() === 'Summary') {
            return $this->renderSummaryTable($experiment, $summary);
        }

        return <<<HTML
<div class="abtesting-report">
  <canvas id="{$chartId}" class="abtesting-chart" height="160"
    data-abtesting-chart='{$chartPayload}'></canvas>
</div>
HTML;
    }

    /**
     * @param list<array{key:string,title:string,exposures:int,conversions:int,rate:float,is_control:bool}> $summary
     */
    private function renderSummaryTable(ABTestExperiment $experiment, array $summary): string
    {
        $bestRate = 0.0;
        $totalExposures = 0;
        $totalConversions = 0;

        foreach ($summary as $row) {
            $bestRate = max($bestRate, $row['rate']);
            $totalExposures += $row['exposures'];
            $totalConversions += $row['conversions'];
        }

        $rows = '';
        foreach ($summary as $row) {
            $isLeader = $bestRate > 0 && abs($row['rate'] - $bestRate) < 0.0001;
            $isWinner = $experiment->WinningVariationKey
                && $experiment->WinningVariationKey === $row['key'];
            $rowClass = trim(
                ($isLeader ? 'is-leader' : '')
                . ($isWinner ? ' is-winner' : '')
                . ($row['is_control'] ? ' is-control' : '')
            );

            $badges = '';
            if ($isWinner) {
                $badges .= '<span class="abtesting-badge abtesting-badge--winner">Winner</span>';
            } elseif ($isLeader && $row['exposures'] > 0) {
                $badges .= '<span class="abtesting-badge abtesting-badge--leader">Leading</span>';
            }
            if ($row['is_control']) {
                $badges .= '<span class="abtesting-badge abtesting-badge--control">Control</span>';
            }

            $rateWidth = $bestRate > 0 ? min(100, ($row['rate'] / $bestRate) * 100) : 0;

            $rows .= sprintf(
                '<tr class="%s">'
                . '<td class="abtesting-summary-table__variation">'
                . '<div class="abtesting-summary-table__heading">'
                . '<span class="abtesting-summary-table__title">%s</span>%s'
                . '</div>'
                . '<span class="abtesting-summary-table__key">%s</span></td>'
                . '<td class="abtesting-summary-table__num">%s</td>'
                . '<td class="abtesting-summary-table__num">%s</td>'
                . '<td class="abtesting-summary-table__rate">'
                . '<div class="abtesting-rate">'
                . '<span class="abtesting-rate__value">%.1f%%</span>'
                . '<span class="abtesting-rate__bar" style="--abtesting-rate:%s%%"></span>'
                . '</div></td>'
                . '</tr>',
                htmlspecialchars($rowClass, ENT_QUOTES),
                htmlspecialchars((string) $row['title'], ENT_QUOTES),
                $badges,
                htmlspecialchars((string) $row['key'], ENT_QUOTES),
                number_format($row['exposures']),
                number_format($row['conversions']),
                $row['rate'],
                number_format($rateWidth, 1, '.', '')
            );
        }

        $overallRate = $totalExposures > 0 ? ($totalConversions / $totalExposures) * 100 : 0.0;

        return <<<HTML
<div class="abtesting-report abtesting-report--summary">
  <div class="abtesting-summary-panel">
    <div class="abtesting-summary-stats">
      <div class="abtesting-stat">
        <span class="abtesting-stat__label">Total exposures</span>
        <span class="abtesting-stat__value">{$this->formatInt($totalExposures)}</span>
      </div>
      <div class="abtesting-stat">
        <span class="abtesting-stat__label">Total conversions</span>
        <span class="abtesting-stat__value">{$this->formatInt($totalConversions)}</span>
      </div>
      <div class="abtesting-stat">
        <span class="abtesting-stat__label">Overall rate</span>
        <span class="abtesting-stat__value">{$this->formatRate($overallRate)}</span>
      </div>
      <div class="abtesting-stat">
        <span class="abtesting-stat__label">Variations</span>
        <span class="abtesting-stat__value">{$this->formatInt(count($summary))}</span>
      </div>
    </div>
    <table class="abtesting-summary-table">
      <thead>
        <tr>
          <th scope="col">Variation</th>
          <th scope="col" class="abtesting-summary-table__num">Exposures</th>
          <th scope="col" class="abtesting-summary-table__num">Conversions</th>
          <th scope="col">Rate</th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>
  </div>
</div>
HTML;
    }

    private function formatInt(int $value): string
    {
        return number_format($value);
    }

    private function formatRate(float $value): string
    {
        return number_format($value, 1) . '%';
    }

    /**
     * @return list<array{key:string,title:string,exposures:int,conversions:int,rate:float,is_control:bool}>
     */
    private function buildSummary(ABTestExperiment $experiment): array
    {
        $rows = [];
        $controlKey = $experiment->ControlKey ?: 'control';

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
                'is_control' => $key === $controlKey,
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
