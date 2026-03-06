<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Console;

use Illuminate\Console\Command;
use Robuust\HerokuQueueListener\QueueAutoscalerListener;
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
    protected $description = 'Scale up a worker dyno when jobs exist within the configured timeframe.';

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
            $this->info('No jobs found in timeframe. Workers will not be scaled up.');

            return self::SUCCESS;
        }

        $this->info("{$queueSize} job(s) found in timeframe. Scaling workers up.");

        app(QueueAutoscalerListener::class)->scaleUpConfiguredWorkers();

        return self::SUCCESS;
    }
}
