<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers and wires queue autoscaling listeners for Laravel applications.
 */
class QueueAutoscalerServiceProvider extends ServiceProvider
{
    /**
     * Register package services and merge default configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue-autoscaler.php', 'queue-autoscaler');
    }

    /**
     * Bootstrap package configuration publishing and event listeners.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/queue-autoscaler.php' => config_path('queue-autoscaler.php'),
        ], 'queue-autoscaler-config');

        Event::listen(JobQueued::class, QueueAutoscalerListener::class);
        Event::listen(JobProcessed::class, QueueAutoscalerListener::class);
        Event::listen(JobFailed::class, QueueAutoscalerListener::class);

        $releaseEvent = config('queue-autoscaler.release_event');

        if (is_string($releaseEvent) && $releaseEvent !== '' && class_exists($releaseEvent)) {
            Event::listen($releaseEvent, QueueAutoscalerListener::class);
        }
    }
}
