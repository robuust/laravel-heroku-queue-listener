<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Robuust\HerokuQueueListener\Jobs\Heartbeat;
use Robuust\HerokuQueueListener\Support\QueueInspector;

class DispatchHeartbeatJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example usage: php artisan workers:dispatch-heartbeat
     *
     * @var string
     */
    protected $signature = 'workers:dispatch-heartbeat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a Heartbeat job to spin up a dyno worker if needed.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $timeframe = (int) config('queue-autoscaler.timeframe_minutes', 2);
        $queueSize = QueueInspector::countJobsWithinTimeframe($timeframe);

        if ($queueSize === 0) {
            $this->info('No jobs found in timeframe. Heartbeat will not be dispatched.');

            return self::SUCCESS;
        }

        $this->info("{$queueSize} job(s) found in timeframe. Dispatching Heartbeat.");

        Queue::push(new Heartbeat());

        return self::SUCCESS;
    }
}
