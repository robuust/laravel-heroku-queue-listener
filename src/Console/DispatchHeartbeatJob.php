<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Robuust\HerokuQueueListener\Jobs\Heartbeat;
use Robuust\HerokuQueueListener\Support\QueueInspector;

class DispatchHeartbeatJob extends Command
{
    protected $signature = 'workers:dispatch-heartbeat {--timeframe= : Time window in minutes to check for queued jobs}';

    protected $description = 'Dispatch a Heartbeat job to spin up a dyno worker if needed.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $timeframe = $this->determineTimeframe();
        $queueSize = QueueInspector::countJobsWithinTimeframe($timeframe);

        if ($queueSize === 0) {
            $this->info('No jobs found in timeframe. Heartbeat will not be dispatched.');

            return self::SUCCESS;
        }

        $this->info("{$queueSize} job(s) found in timeframe. Dispatching Heartbeat.");

        Queue::push(new Heartbeat());

        return self::SUCCESS;
    }

    /**
     * Determine the effective timeframe in minutes from option or package config.
     *
     * @return int
     */
    private function determineTimeframe(): int
    {
        $timeframe = (int) config('queue-autoscaler.timeframe_minutes', 2);
        $option = $this->option('timeframe');

        if (is_numeric($option)) {
            $timeframe = (int) $option;
        }

        return max($timeframe, 0);
    }
}
