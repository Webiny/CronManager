<?php

namespace Apps\CronManager\Php\Entities;

use Apps\CronManager\Php\Interfaces\CronJobInterface;
use Apps\Webiny\Php\Lib\Api\ApiContainer;
use Apps\Webiny\Php\Lib\Entity\Indexes\IndexContainer;
use Apps\Webiny\Php\Lib\Exceptions\AppException;
use Apps\Webiny\Php\Lib\Entity\AbstractEntity;
use Webiny\Component\Mongo\Index\CompoundIndex;

/**
 * Class Jobs
 *
 * @property string $id
 * @property string $name
 * @property string $description
 * @property string $frequency
 * @property string $timezone
 * @property string $targetType
 * @property string $target
 * @property int    $timeout
 * @property array  $notifyOn
 * @property array  $notifyEmails
 * @property bool   $enabled
 * @property int    $lastRunDate
 * @property int    $nextRunDate
 * @property string $status
 * @property bool   $isInactive
 * @property bool   $isScheduled
 * @property bool   $isRunning
 * @property array  $stats
 */
class Job extends AbstractEntity
{
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_RUNNING = 'running';

    const TARGET_URL = 'url';
    const TARGET_CLASS = 'class';

    protected static $classId = 'CronManager.Entities.Job';
    protected static $i18nNamespace = 'CronManager.Entities.Job';
    protected static $collection = 'CronManagerJobs';
    protected static $mask = '{name}';

    public function __construct()
    {
        parent::__construct();

        $this->attr('name')->char()->setValidators('required,unique')->setValidationMessages([
            'unique' => 'This cron job already exists.'
        ])->setToArrayDefault();

        $this->attr('description')->char()->setToArrayDefault();
        $this->attr('timeout')->integer()->setToArrayDefault();
        $this->attr('timezone')->char()->setValidators('required')->setToArrayDefault();

        $this->attr('notifyOn')->arr()->setToArrayDefault();
        $this->attr('notifyEmails')->arr()->setToArrayDefault();

        $this->attr('status')->char()->setDefaultValue(self::STATUS_INACTIVE)->setValidators('in:inactive:scheduled:running');
        $this->attr('enabled')->boolean()->setDefaultValue(true)->setToArrayDefault()->onSet(function ($enabled) {
            // in case we create a new cron job, or in case if we re-enable a disabled cron job, we need to set the next run date
            $this->scheduleNextRunDate();
            if ($enabled) {
                $this->status = self::STATUS_SCHEDULED;
            } else {
                $this->status = self::STATUS_INACTIVE;
            }

            return $enabled;
        })->setAfterPopulate();

        $this->attr('target')->char()->onSet(function ($value) {
            return trim($value);
        })->setToArrayDefault()->setValidators('required');

        $this->attr('targetType')->char()->setToArrayDefault()->setValidators('required,in:url:class');
        $this->attr('frequency')->many2one()->setEntity(JobFrequency::class);
        $this->attr('nextRunDate')->char()->setToArrayDefault();
        $this->attr('lastRunDate')->datetime();

        $this->attr('isInactive')->dynamic(function () {
            return $this->status === self::STATUS_INACTIVE;
        });

        $this->attr('isScheduled')->dynamic(function () {
            return $this->status === self::STATUS_SCHEDULED;
        });

        $this->attr('isRunning')->dynamic(function () {
            return $this->status === self::STATUS_RUNNING;
        });

        $this->attr('stats')->object()->setDefaultValue([
            'totalExecTime'  => 0,
            'numberOfRuns'   => 0,
            'successfulRuns' => 0
        ])->setToArrayDefault();
    }

    protected function entityApi(ApiContainer $api)
    {
        parent::entityApi($api);

        $api->get('timezones', function () {
            return $this->listTimezones();
        });

        $api->get('{id}/history', function () {
            $params = [
                ['job' => $this->id] + $this->wRequest()->getFilters(),
                $this->wRequest()->getSortFields(),
                $this->wRequest()->getPerPage(),
                $this->wRequest()->getPage()
            ];

            return $this->apiFormatList(JobHistory::find(...$params), $this->wRequest()->getFields());
        });

        $api->post('validators/targets/class-names', function () {
            $className = $this->wRequest()->getRequestData()['className'];
            $this->validateClassTarget($className);
        })->setBodyValidators(['className' => 'required']);
    }

    protected static function entityIndexes(IndexContainer $indexes)
    {
        parent::entityIndexes($indexes);
        $indexes->add(new CompoundIndex('name', ['name', 'deletedOn'], false, true));
    }

    public function scheduleNextRunDate()
    {
        // set timezone
        date_default_timezone_set(str_replace(' ', '_', $this->timezone));

        $cronRunner = \Cron\CronExpression::factory($this->frequency->mask);
        $runDate = $cronRunner->getNextRunDate('now', 0, true);

        // we need to have at least one minute offset between the current date and the next run date
        if ($runDate->format('U') - time() <= 60) {
            $runDate = $cronRunner->getNextRunDate('now', 1, true);
        }

        $this->nextRunDate = $runDate->format('c');
    }

    /**
     * This determines if this job can be triggered when Runner is triggered
     * Job can be triggered if following conditions are met:
     *  - current time has passed job's execution time
     *  - current job is not already in 'running' state (can happen if cron job takes a bit longer to run)
     * @return bool
     */
    public function shouldJobRunNow()
    {
        if (!$this->enabled) {
            return false;
        }

        // check if job is hanging, if so, let's run it again
        if ($this->isRunning) {
            if (time() > ($this->timeout + strtotime($this->lastRunDate))) {
                return true;
            }
        }

        $tz = date_default_timezone_get();
        date_default_timezone_set(str_replace(' ', '_', $this->timezone));

        // Get the timestamp of the job
        $jobTs = $this->datetime($this->nextRunDate)->format('U');
        date_default_timezone_set($tz);

        return time() > $jobTs;
    }

    public function listTimezones()
    {
        $timezone_identifiers = \DateTimeZone::listIdentifiers();

        $result = [];
        foreach ($timezone_identifiers as $ti) {
            $result[] = str_replace('_', ' ', $ti);
        }

        return $result;
    }

    /**
     * Returns if given class name is a valid cron job target class
     *
     * @param $className
     *
     * @throws AppException
     */
    private function validateClassTarget($className)
    {
        // Working example - Apps\TestApp\Php\Services\Crons\UpdateStats
        $re = '/Apps\\\\(.*)\\\\Php\\\\(.*)/';
        preg_match_all($re, $className, $matches);

        if (empty($matches[0])) {
            throw new AppException($this->wI18n('Invalid namespace.'));
        }

        $className = $this->wRequest()->getRequestData()['className'];

        $parts = $this->str($className)->explode('\\')->filter()->values()->val();
        $classFile = $this->wConfig()->get('Webiny.AbsolutePath') . join('/', $parts) . '.php';
        if (!file_exists($classFile)) {
            throw new AppException($this->wI18n('Namespace is valid but file does not exist.'));
        }

        if (!class_exists($className)) {
            throw new AppException($this->wI18n('Namespace is valid but given class was not found in the file.'));
        }

        $classInterfaces = class_implements($className);
        if (!isset($classInterfaces[CronJobInterface::class])) {
            throw new AppException($this->wI18n('Class must implement CronJobInterface.'));
        }
    }
}