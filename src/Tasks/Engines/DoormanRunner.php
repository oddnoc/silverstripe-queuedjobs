<?php

namespace Symbiote\QueuedJobs\Tasks\Engines;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Jobs\DoormanQueuedJobTask;
use Symbiote\QueuedJobs\Services\ProcessManager;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Runs all jobs through the doorman engine
 */
class DoormanRunner extends BaseRunner implements TaskRunnerEngine
{
    use Configurable;

    /**
     * @var string[]
     */
    protected $defaultRules = [];

    /**
     * Name of the dev task used to run the child process
     *
     * @config
     * @var string
     */
    private static $child_runner = 'ProcessJobQueueChildTask';

    /**
     * How many ticks are executed per one @see runQueue method call
     * set 0 for unlimited ticks
     *
     * @config
     * @var int
     */
    private static $max_ticks = 0;

    /**
     * How many seconds between ticks
     *
     * @config
     * @var int
     */
    private static $tick_interval = 1;

    /**
     * @return array List of rules
     */
    public function getDefaultRules()
    {
        return $this->defaultRules;
    }

    /**
     * Run tasks on the given queue
     *
     * @param string $queue
     */
    public function runQueue($queue)
    {
        $service = QueuedJobService::singleton();
        $logger  = $service->getLogger();

        // check if queue can be processed
        if ($service->isAtMaxJobs()) {
            $logger->info('Not processing queue as jobs are at max initialisation limit.');

            return;
        }

        // split jobs out into multiple tasks...

        /** @var ProcessManager $manager */
        $manager = Injector::inst()->create(ProcessManager::class);
        $manager->setWorker(
            sprintf(
                '%s/vendor/silverstripe/framework/cli-script.php dev/tasks/%s',
                BASE_PATH,
                $this->getChildRunner()
            )
        );

        $logPath = Environment::getEnv('SS_DOORMAN_LOGPATH');

        if ($logPath) {
            $manager->setLogPath($logPath);
        }

        // Assign default rules
        $defaultRules = $this->getDefaultRules();

        if ($defaultRules) {
            foreach ($defaultRules as $rule) {
                if (!$rule) {
                    continue;
                }

                $manager->addRule($rule);
            }
        }

        $tickCount  = 0;
        $maxTicks   = $this->getMaxTicks();
        $descriptor = $service->getNextPendingJob($queue);

        while ($manager->tick() || $descriptor) {
            if ($service->isMaintenanceLockActive()) {
                $logger->info('Skipped queued job descriptor since maintenance log is active.');

                return;
            }

            if ($maxTicks > 0 && $tickCount >= $maxTicks) {
                $logger->info(sprintf('Tick count has hit max ticks %d >= %d', $tickCount, $maxTicks));

                return;
            }

            if ($service->isAtMaxJobs()) {
                $logger->info(
                    sprintf(
                        'Not processing queue as all job are at max limit. %s',
                        ClassInfo::shortName($service)
                    )
                );
            } elseif ($descriptor) {
                $logger->info(sprintf('Next pending job is: %d', $descriptor->ID));
                $this->logDescriptorStatus($descriptor, $queue);

                if ($descriptor instanceof QueuedJobDescriptor) {
                    $descriptor->JobStatus = QueuedJob::STATUS_INIT;
                    $descriptor->write();

                    $manager->addTask(new DoormanQueuedJobTask($descriptor));
                }
            } else {
                $logger->info('Next pending job could NOT be found or lock could NOT be obtained.');
            }

            $tickCount += 1;
            sleep($this->getTickInterval());
            $descriptor = $service->getNextPendingJob($queue);
        }
    }

    /**
     * Assign default rules for this task
     *
     * @param array $rules
     * @return $this
     */
    public function setDefaultRules($rules)
    {
        $this->defaultRules = $rules;
        return $this;
    }

    /**
     * Override this method if you need a dynamic value for the configuration, for example CMS setting
     *
     * @return string
     */
    protected function getChildRunner(): string
    {
        return (string) $this->config()->get('child_runner');
    }

    /**
     * Override this method if you need a dynamic value for the configuration, for example CMS setting
     *
     * @return int
     */
    protected function getMaxTicks(): int
    {
        return (int) $this->config()->get('max_ticks');
    }

    /**
     * @param string $queue
     * @return QueuedJobDescriptor|null
     * @deprecated 5.0
     */
    protected function getNextJobDescriptorWithoutMutex($queue)
    {
        return $this->getService()->getNextPendingJob($queue);
    }

    /**
     * Override this method if you need a dynamic value for the configuration, for example CMS setting
     *
     * @return int
     */
    protected function getTickInterval(): int
    {
        return (int) $this->config()->get('tick_interval');
    }
}
