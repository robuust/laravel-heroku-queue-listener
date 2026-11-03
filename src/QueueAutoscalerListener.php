<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener;

use Exception;
use HerokuClient\Client;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Serializes worker scaling requests and defers retries to subsequent queue events.
 */
class QueueAutoscalerListener
{
    /** @var int Maximum duration of a Heroku request, including connection setup. */
    private const HTTP_TIMEOUT_SECONDS = 5;

    /**
     * Handle queue events and autoscale workers.
     *
     * @param  object  $event  The queue or application release event.
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
            if ($event instanceof JobProcessed || $event instanceof JobFailed) {
                $this->stopWorker();
            }

            $this->scaleWorkers($appName, $apiKey, 0);
        }
    }

    /**
     * Stop the current worker before Heroku shuts down the dyno.
     */
    protected function stopWorker(): void
    {
        /** @var Worker $worker */
        $worker = app('queue.worker');
        $worker->shouldQuit = true;
    }

    /**
     * Make at most one bounded scaling request per event using a non-blocking lock.
     *
     * @param  string  $appName  The Heroku application name.
     * @param  string  $apiKey  The Heroku API credential.
     * @param  int  $quantity  The worker count requested by this event.
     */
    protected function scaleWorkers(string $appName, string $apiKey, int $quantity): void
    {
        $cacheKey = (string) config('queue-autoscaler.cache_key', 'queue-autoscaler:current-dynos').':'.sha1($appName);
        $lock = Cache::lock($cacheKey.':lock', self::HTTP_TIMEOUT_SECONDS + 5);

        if (! $lock->get()) {
            return;
        }

        try {
            $retry = Cache::get($cacheKey.':retry', ['failures' => 0, 'after' => 0]);

            if ($retry['after'] > now()->getTimestamp()) {
                return;
            }

            $currentDynos = Cache::get($cacheKey);

            if ($currentDynos === $quantity) {
                return;
            }

            $client = new Client([
                'apiKey' => $apiKey,
                'curlOptions' => [
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
                ],
            ]);

            try {
                $client->patch('apps/'.rawurlencode($appName).'/formation/worker', ['quantity' => $quantity]);
            } catch (Exception $exception) {
                $response = $client->getLastHttpResponse();
                $httpStatus = $response?->getStatusCode();
                $failures = min($retry['failures'] + 1, 5);
                $delay = (new RetryPolicy)->delay($failures, $response);

                // A timed-out PATCH may already have changed the formation.
                Cache::forget($cacheKey);
                Cache::forever($cacheKey.':retry', [
                    'failures' => $failures,
                    'after' => now()->getTimestamp() + $delay,
                ]);

                Log::warning('Queue autoscaler request failed', [
                    'to' => $quantity,
                    'http_status' => $httpStatus,
                    'request_id' => $response?->getHeaderLine('Request-Id'),
                    'rate_limit_remaining' => $response?->getHeaderLine('RateLimit-Remaining'),
                    'retry_after' => $response?->getHeaderLine('Retry-After'),
                    'retry_in_seconds' => $delay,
                    'exception_class' => $exception::class,
                ]);

                // Transport exceptions can retain an authenticated request; do not report it.
                report(new Exception('Failed to autoscale workers ('.$exception::class.', HTTP '.($httpStatus ?? 'unavailable').')'));

                return;
            }

            Cache::put($cacheKey, $quantity, now()->addSeconds((int) config('queue-autoscaler.cache_ttl_seconds', 3600)));
            Cache::forget($cacheKey.':retry');

            Log::info('Queue autoscaler adjusted workers', [
                'from' => $currentDynos,
                'to' => $quantity,
            ]);
        } catch (Exception $exception) {
            report(new Exception('Failed to record worker scaling ('.$exception::class.')'));
        } finally {
            $lock->release();
        }
    }
}
