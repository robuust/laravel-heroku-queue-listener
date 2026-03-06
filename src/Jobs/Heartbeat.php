<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Pulse job to wake workers for delayed/backoff jobs when autoscaled to zero.
 */
class Heartbeat implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('Queue autoscaler heartbeat pulse sent.');
    }
}
