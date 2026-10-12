<?php

namespace FullscreenInteractive\ABTesting\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * One visitor exposure (and optional conversion) for an experiment.
 *
 * @property string $VisitorID
 * @property string $WhenRendered
 * @property string $WhenConverted
 * @property string $VariationKey
 * @property int $Assertiveness
 * @property string $UserAgent
 * @property int $ExperimentID
 * @method ABTestExperiment Experiment()
 */
class ABTestData extends DataObject
{
    private static string $table_name = 'ABTestData';

    private static string $singular_name = 'Experiment data point';

    private static string $plural_name = 'Experiment data';

    private static string $default_sort = '"ID" DESC';

    private static array $db = [
        'VisitorID' => 'Varchar(128)',
        'WhenRendered' => 'Datetime',
        'WhenConverted' => 'Datetime',
        'VariationKey' => 'Varchar(50)',
        'Assertiveness' => 'Int',
        'UserAgent' => 'Varchar(255)',
        // Legacy column kept for upgrades from the original module.
        'PHPSessionID' => 'Varchar(128)',
        'StateVariableValue' => 'Varchar(50)',
    ];

    private static array $has_one = [
        'Experiment' => ABTestExperiment::class,
    ];

    private static array $indexes = [
        'ExperimentVisitor' => [
            'type' => 'unique',
            'columns' => ['ExperimentID', 'VisitorID'],
        ],
        'ExperimentVariation' => [
            'columns' => ['ExperimentID', 'VariationKey'],
        ],
    ];

    private static array $defaults = [
        'Assertiveness' => 0,
    ];

    private static array $summary_fields = [
        'VariationKey' => 'Variation',
        'WhenRendered.Nice' => 'Exposed',
        'WhenConverted.Nice' => 'Converted',
        'Assertiveness' => 'Assertiveness',
    ];

    private static array $searchable_fields = [
        'VariationKey',
        'VisitorID',
        'Assertiveness',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->makeFieldReadonly([
            'VisitorID',
            'WhenRendered',
            'WhenConverted',
            'VariationKey',
            'Assertiveness',
            'UserAgent',
            'PHPSessionID',
            'StateVariableValue',
            'ExperimentID',
        ]);

        return $fields;
    }

    public function isConverted(): bool
    {
        return (bool) $this->WhenConverted;
    }

    public function markConverted(int $assertiveness = 100): void
    {
        if ($this->isConverted()) {
            return;
        }

        $this->WhenConverted = DBDatetime::now()->Rfc2822();
        $this->Assertiveness = max(0, min(100, $assertiveness));
        $this->write();
    }
}
