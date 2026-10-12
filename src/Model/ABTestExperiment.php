<?php

namespace FullscreenInteractive\ABTesting\Model;

use FullscreenInteractive\ABTesting\Form\ExperimentReportField;
use FullscreenInteractive\ABTesting\Service\VariationAssigner;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * An A/B test experiment targeting a page and measuring conversion.
 *
 * @property string $Title
 * @property string $Key
 * @property string $Comments
 * @property string $Status
 * @property string $StartDate
 * @property string $EndDate
 * @property string $StateMechanism
 * @property string $CookieName
 * @property string $ControlKey
 * @property string $ControlTitle
 * @property int $ControlWeight
 * @property int $TrafficPercent
 * @property string $VariationQueryVariable
 * @property string $WebhookURL
 * @property bool $EnableDataLayer
 * @property string $WinningVariationKey
 * @property int $TestedPageID
 * @property int $ConversionPageID
 * @method SiteTree TestedPage()
 * @method SiteTree ConversionPage()
 * @method HasManyList<ABTestVariation> Variations()
 * @method HasManyList<ABTestData> Results()
 */
class ABTestExperiment extends DataObject implements PermissionProvider
{
    private static string $table_name = 'ABTestExperiment';

    private static string $singular_name = 'Experiment';

    private static string $plural_name = 'Experiments';

    private static string $default_sort = '"LastEdited" DESC';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Key' => 'Varchar(100)',
        'Comments' => 'Text',
        'Status' => "Enum('Draft,Active,Paused,Complete','Draft')",
        'StartDate' => 'Datetime',
        'EndDate' => 'Datetime',
        'StateMechanism' => "Enum('Cookie,Session','Cookie')",
        'CookieName' => 'Varchar(100)',
        'ControlKey' => 'Varchar(50)',
        'ControlTitle' => 'Varchar(255)',
        'ControlWeight' => 'Int',
        'TrafficPercent' => 'Int',
        'VariationQueryVariable' => 'Varchar(50)',
        'WebhookURL' => 'Varchar(255)',
        'EnableDataLayer' => 'Boolean',
        'WinningVariationKey' => 'Varchar(50)',
    ];

    private static array $has_one = [
        'TestedPage' => SiteTree::class,
        'ConversionPage' => SiteTree::class,
    ];

    private static array $has_many = [
        'Variations' => ABTestVariation::class,
        // Named Results (not Data) — DataObject scaffolding treats "Data" specially in SS6.
        'Results' => ABTestData::class,
    ];

    private static array $defaults = [
        'Status' => 'Draft',
        'StateMechanism' => 'Cookie',
        'ControlKey' => 'control',
        'ControlTitle' => 'Control (original page)',
        'ControlWeight' => 50,
        'TrafficPercent' => 100,
        'VariationQueryVariable' => 'abv',
        'EnableDataLayer' => true,
    ];

    private static array $summary_fields = [
        'Title' => 'Title',
        'Key' => 'Key',
        'Status' => 'Status',
        'TestedPage.Title' => 'Tested page',
        'ConversionPage.Title' => 'Conversion page',
        'StartDate.Nice' => 'Started',
    ];

    private static array $searchable_fields = [
        'Title',
        'Key',
        'Status',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'TestedPageID',
            'ConversionPageID',
            'Variations',
            'Results',
            'StartDate',
            'EndDate',
            'WinningVariationKey',
        ]);

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Title'),
            TextField::create('Key', 'Experiment key')
                ->setDescription(
                    'Stable identifier used in dataLayer and tracking payloads. '
                    . 'Auto-generated from the title if left blank.'
                ),
            DropdownField::create('Status', 'Status', $this->dbObject('Status')->enumValues()),
            TextareaField::create('Comments', 'Notes'),
            TreeDropdownField::create('TestedPageID', 'Tested page', SiteTree::class),
            TreeDropdownField::create('ConversionPageID', 'Conversion page', SiteTree::class)
                ->setDescription(
                    'Visitors who reach this page after seeing the tested page are counted as '
                    . 'conversions. Leave blank if you only use custom JS / API conversions.'
                ),
            ReadonlyField::create('StartDateNice', 'Started', $this->StartDate),
            ReadonlyField::create('EndDateNice', 'Ended', $this->EndDate),
        ]);

        $fields->addFieldsToTab('Root.Assignment', [
            HeaderField::create('AssignmentHeader', 'Visitor assignment'),
            DropdownField::create(
                'StateMechanism',
                'Persist variation with',
                $this->dbObject('StateMechanism')->enumValues()
            ),
            TextField::create('CookieName', 'Cookie name')
                ->setDescription('Optional. Defaults to ab_exp_{ID}.'),
            TextField::create('ControlKey', 'Control variation key'),
            TextField::create('ControlTitle', 'Control variation title'),
            NumericField::create('ControlWeight', 'Control traffic weight')
                ->setDescription(
                    'Relative weight against variation weights. Higher = more traffic.'
                ),
            NumericField::create('TrafficPercent', 'Enrolment percentage')
                ->setDescription(
                    'Percentage of visitors to enrol in this experiment (0–100). '
                    . 'Unenrolled visitors always see the control and are not tracked.'
                ),
            TextField::create('VariationQueryVariable', 'Force variation query parameter')
                ->setDescription(
                    'When present in the URL, forces a variation key (useful for QA). '
                    . 'Example: ?abv=variant-b'
                ),
        ]);

        $fields->addFieldsToTab('Root.Tracking', [
            HeaderField::create('TrackingHeader', 'Analytics & integrations'),
            DropdownField::create(
                'EnableDataLayer',
                'Push events to Google dataLayer',
                [1 => 'Yes', 0 => 'No']
            ),
            TextField::create('WebhookURL', 'Experiment webhook URL')
                ->setDescription(
                    'Optional HTTPS endpoint that receives JSON exposure and conversion events '
                    . 'for this experiment. See README for payload shape.'
                ),
            LiteralField::create(
                'TrackingHelp',
                '<p class="abtesting-help">Global webhook endpoints and custom tracking bridges '
                . 'can also be registered in YAML / Injector. Frontend helpers are available via '
                . '<code>window.SSABTesting</code>.</p>'
            ),
        ]);

        if ($this->isInDB()) {
            $variationsConfig = GridFieldConfig_RecordEditor::create();
            $variationsConfig->addComponent(GridFieldOrderableRows::create('Sort'));

            $fields->addFieldToTab(
                'Root.Variations',
                GridField::create(
                    'Variations',
                    'Variations',
                    $this->Variations(),
                    $variationsConfig
                )
            );

            $fields->addFieldsToTab('Root.Results', [
                DropdownField::create(
                    'WinningVariationKey',
                    'Declared winner',
                    $this->getVariationTitleMap()
                )->setEmptyString('No winner declared'),
                ExperimentReportField::create('Summary', 'Summary', $this),
                ExperimentReportField::create('ConversionRate', 'Conversion rate over time', $this),
                ExperimentReportField::create('RenderRaw', 'Exposures over time', $this),
            ]);
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->Title) {
            $result->addFieldError('Title', 'Title is required');
        }

        if ($this->TrafficPercent < 0 || $this->TrafficPercent > 100) {
            $result->addFieldError('TrafficPercent', 'Traffic percent must be between 0 and 100');
        }

        if ($this->ControlWeight < 0) {
            $result->addFieldError('ControlWeight', 'Control weight cannot be negative');
        }

        if ($this->Status === 'Active' && !$this->TestedPageID) {
            $result->addFieldError('TestedPageID', 'An active experiment requires a tested page');
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->Key && $this->Title) {
            $this->Key = $this->generateKey($this->Title);
        }

        if ($this->Status === 'Active' && !$this->StartDate) {
            $this->StartDate = DBDatetime::now()->Rfc2822();
        }

        if ($this->Status === 'Complete' && !$this->EndDate) {
            $this->EndDate = DBDatetime::now()->Rfc2822();
        }
    }

    public function getResolvedCookieName(): string
    {
        if ($this->CookieName) {
            return $this->CookieName;
        }

        return 'ab_exp_' . ($this->ID ?: 'new');
    }

    /**
     * @return array<string, string>
     */
    public function getVariationTitleMap(): array
    {
        $map = [
            $this->ControlKey ?: 'control' => $this->ControlTitle ?: 'Control',
        ];

        foreach ($this->Variations() as $variation) {
            $map[$variation->VariationKey] = $variation->Title;
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    public function getVariationWeights(): array
    {
        $weights = [
            $this->ControlKey ?: 'control' => max(0, (int) $this->ControlWeight),
        ];

        foreach ($this->Variations() as $variation) {
            $weights[$variation->VariationKey] = max(0, (int) $variation->Weight);
        }

        return $weights;
    }

    public function getVariationByKey(?string $key): ?ABTestVariation
    {
        if (!$key || $key === ($this->ControlKey ?: 'control')) {
            return null;
        }

        /** @var ABTestVariation|null $variation */
        $variation = $this->Variations()->find('VariationKey', $key);

        return $variation;
    }

    public function isRunning(): bool
    {
        return $this->Status === 'Active';
    }

    public function providePermissions(): array
    {
        return [
            'ABTESTING_MANAGE' => [
                'name' => 'Manage A/B testing experiments',
                'category' => 'A/B Testing',
            ],
        ];
    }

    public function canView($member = null): bool
    {
        return Permission::check('ABTESTING_MANAGE', 'any', $member)
            || Permission::check('CMS_ACCESS_FullscreenInteractive\\ABTesting\\Admin\\ABTestAdmin', 'any', $member)
            || Permission::check('CMS_ACCESS_LeftAndMain', 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return $this->canView($member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return $this->canView($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canView($member) && $this->Status !== 'Active';
    }

    public static function getActiveForPage(SiteTree $page): ?self
    {
        /** @var self|null $experiment */
        $experiment = static::get()->filter([
            'TestedPageID' => $page->ID,
            'Status' => 'Active',
        ])->first();

        return $experiment;
    }

    /**
     * @return \SilverStripe\ORM\DataList<self>
     */
    public static function getActiveForConversionPage(SiteTree $page)
    {
        return static::get()->filter([
            'ConversionPageID' => $page->ID,
            'Status' => 'Active',
        ]);
    }

    private function generateKey(string $title): string
    {
        $key = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));

        return $key !== '' ? $key : 'experiment-' . uniqid();
    }

    /**
     * Convenience helper used by templates / extensions.
     */
    public function assigner(): VariationAssigner
    {
        return VariationAssigner::create($this);
    }
}
