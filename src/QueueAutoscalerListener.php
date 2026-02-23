<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener;

use Exception;
use HerokuClient\Client;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class QueueAutoscalerListener
{
    /**
     * Handle queue events and autoscale workers.
     */
    public function handle(object $event): void
    {
        $apiKey = (string) config('queue-autoscaler.heroku_api_key');
        $appName = (string) config('queue-autoscaler.heroku_app_name');

        if ($apiKey === '' || $appName === '') {
            return;
        }

        $releaseEventClass = config('queue-autoscaler.release_event');
        $isReleaseEvent = is_string($releaseEventClass) && $releaseEventClass !== '' && is_a($event, $releaseEventClass);

        $shouldScaleUp = $event instanceof JobQueued || ($isReleaseEvent && Queue::size() > 0);

        if ($shouldScaleUp) {
            $this->scaleWorkers($appName, $apiKey, 1);
            return;
        }

        if (Queue::size() === 0) {
            $this->scaleWorkers($appName, $apiKey, 0);
        }
    }

    /**
     * Scale Heroku workers to the specified quantity.
     */
    protected function scaleWorkers(string $appName, string $apiKey, int $quantity): void
    {
        $cacheKey = (string) config('queue-autoscaler.cache_key', 'queue-autoscaler:current-dynos');
        $cacheTtl = (int) config('queue-autoscaler.cache_ttl_seconds', 3600);
        $currentDynos = Cache::get($cacheKey);

        if ($currentDynos === $quantity) {
            return;
        }

        try {
            $client = new Client(['apiKey' => $apiKey]);
            $endpoint = "apps/{$appName}/formation/worker";

            $client->patch($endpoint, ['quantity' => $quantity]);

            Cache::put($cacheKey, $quantity, now()->addSeconds($cacheTtl));

            Log::info('Queue autoscaler adjusted workers', [
                'from' => $currentDynos,
                'to' => $quantity,
            ]);
        } catch (Exception $exception) {
            report(new Exception('Failed to autoscale workers', 0, $exception));
        }
    }
}
