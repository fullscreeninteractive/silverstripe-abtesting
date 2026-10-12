<?php

namespace FullscreenInteractive\ABTesting\Model;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\ORM\DataObject;

/**
 * A single variation within an experiment.
 *
 * @property string $Title
 * @property string $VariationKey
 * @property int $Weight
 * @property int $Sort
 * @property string $Presentation
 * @property string $AlternateTemplate
 * @property int $ExperimentID
 * @property int $AlternatePageID
 * @property int $ConversionPageID
 * @method ABTestExperiment Experiment()
 * @method SiteTree AlternatePage()
 * @method SiteTree ConversionPage()
 */
class ABTestVariation extends DataObject
{
    private static string $table_name = 'ABTestVariation';

    private static string $singular_name = 'Variation';

    private static string $plural_name = 'Variations';

    private static string $default_sort = '"Sort" ASC, "ID" ASC';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'VariationKey' => 'Varchar(50)',
        'Weight' => 'Int',
        'Sort' => 'Int',
        'Presentation' => "Enum('None,AlternateTemplate','None')",
        'AlternateTemplate' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Experiment' => ABTestExperiment::class,
        'AlternatePage' => SiteTree::class,
        'ConversionPage' => SiteTree::class,
    ];

    private static array $defaults = [
        'Weight' => 50,
        'Presentation' => 'None',
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'VariationKey' => 'Key',
        'Weight' => 'Weight',
        'Presentation' => 'Presentation',
        'AlternatePage.Title' => 'Alternate page',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'ExperimentID',
            'AlternatePageID',
            'ConversionPageID',
            'Sort',
        ]);

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Title'),
            TextField::create('VariationKey', 'Variation key')
                ->setDescription(
                    'Stable key used in cookies, dataLayer, and APIs (e.g. variant-b).'
                ),
            NumericField::create('Weight', 'Traffic weight')
                ->setDescription('Relative weight versus the control and other variations.'),
            TreeDropdownField::create(
                'AlternatePageID',
                'Alternate page content',
                SiteTree::class
            )->setDescription(
                'Optional. When set, content fields are copied from this page at request time '
                . '(URL stays the same).'
            ),
            DropdownField::create(
                'Presentation',
                'Template presentation',
                $this->dbObject('Presentation')->enumValues()
            ),
            TextField::create('AlternateTemplate', 'Alternate template(s)')
                ->setDescription(
                    'Used when presentation is AlternateTemplate. Comma-separated template names, '
                    . 'e.g. LandingPageVariant,Page'
                ),
            TreeDropdownField::create(
                'ConversionPageID',
                'Variation conversion page',
                SiteTree::class
            )->setDescription(
                'Optional override. When set, conversions for visitors on this variation are '
                . 'counted on this page instead of the experiment default.'
            ),
        ]);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Title) {
            $result->addFieldError('Title', 'Title is required');
        }

        if (!$this->VariationKey) {
            $result->addFieldError('VariationKey', 'Variation key is required');
        }

        if ($this->Weight < 0) {
            $result->addFieldError('Weight', 'Weight cannot be negative');
        }

        if ($this->Presentation === 'AlternateTemplate' && !$this->AlternateTemplate) {
            $result->addFieldError(
                'AlternateTemplate',
                'Alternate template is required for this presentation mode'
            );
        }

        $experiment = $this->Experiment();
        if ($experiment && $experiment->exists() && $this->VariationKey) {
            if ($this->VariationKey === ($experiment->ControlKey ?: 'control')) {
                $result->addFieldError(
                    'VariationKey',
                    'Variation key must not match the experiment control key'
                );
            }

            $duplicate = ABTestVariation::get()->filter([
                'ExperimentID' => $experiment->ID,
                'VariationKey' => $this->VariationKey,
            ]);

            if ($this->isInDB()) {
                $duplicate = $duplicate->exclude('ID', $this->ID);
            }

            if ($duplicate->exists()) {
                $result->addFieldError(
                    'VariationKey',
                    'Variation key must be unique within the experiment'
                );
            }
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->VariationKey && $this->Title) {
            $this->VariationKey = strtolower(
                trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $this->Title) ?? '', '-')
            );
        }
    }
}
