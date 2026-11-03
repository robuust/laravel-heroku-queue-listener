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
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Coalesces worker scaling requests and defers retries without delaying queue jobs.
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
     * Scale Heroku workers to the specified quantity.
     *
     * @param  string  $appName  The Heroku application name.
     * @param  string  $apiKey  The Heroku API credential.
     * @param  int  $quantity  The latest desired worker count.
     */
    protected function scaleWorkers(string $appName, string $apiKey, int $quantity): void
    {
        $cacheKey = $this->cacheKey($appName);
        Cache::forever($cacheKey.':desired', $quantity);

        $this->synchronize($appName, $apiKey, true);
    }

    /**
     * Retry pending changes from the scheduler, independently of worker availability.
     */
    public function retryPending(): void
    {
        $apiKey = (string) config('queue-autoscaler.heroku_api_key');
        $appName = (string) config('queue-autoscaler.heroku_app_name');

        if ($apiKey !== '' && $appName !== '') {
            $this->synchronize($appName, $apiKey);
        }
    }

    /**
     * Make at most one bounded request while holding a non-blocking application lock.
     *
     * @param  string  $appName  The Heroku application name.
     * @param  string  $apiKey  The Heroku API credential.
     * @param  bool  $fromEvent  Whether an event should recheck the expiring current-count cache.
     */
    private function synchronize(string $appName, string $apiKey, bool $fromEvent = false): void
    {
        $cacheKey = $this->cacheKey($appName);
        $lock = Cache::lock($cacheKey.':lock', self::HTTP_TIMEOUT_SECONDS + 5);

        if (! $lock->get()) {
            return;
        }

        try {
            $quantity = Cache::get($cacheKey.':desired');
            $retry = Cache::get($cacheKey.':retry', ['failures' => 0, 'after' => 0]);

            if ($quantity === null
                || (! $fromEvent && Cache::get($cacheKey.':settled') === $quantity)
                || $retry['after'] > now()->getTimestamp()) {
                return;
            }

            $currentDynos = Cache::get($cacheKey);

            if ($currentDynos === $quantity) {
                Cache::forever($cacheKey.':settled', $quantity);

                return;
            }

            $client = $this->createClient($apiKey);

            try {
                $client->patch('apps/'.rawurlencode($appName).'/formation/worker', ['quantity' => $quantity]);
            } catch (Exception $exception) {
                $response = $client->getLastHttpResponse();
                $httpStatus = $response?->getStatusCode();
                $retryable = $exception instanceof NetworkExceptionInterface
                    || in_array($httpStatus, [408, 429], true)
                    || ($httpStatus !== null && $httpStatus >= 500);
                $failures = min($retry['failures'] + 1, 5);
                $delay = (new RetryPolicy)->delay($failures, $response);

                // A timed-out PATCH may already have changed the formation.
                Cache::forget($cacheKey);
                Cache::forget($cacheKey.':settled');
                Cache::forever($cacheKey.':retry', [
                    'failures' => $failures,
                    'after' => now()->getTimestamp() + $delay,
                ]);

                if (! $retryable) {
                    Cache::forever($cacheKey.':settled', $quantity);
                }

                Log::warning('Queue autoscaler request failed', [
                    'to' => $quantity,
                    'http_status' => $httpStatus,
                    'request_id' => $response?->getHeaderLine('Request-Id'),
                    'rate_limit_remaining' => $response?->getHeaderLine('RateLimit-Remaining'),
                    'retry_after' => $response?->getHeaderLine('Retry-After'),
                    'retry_in_seconds' => $retryable ? $delay : null,
                    'exception_class' => $exception::class,
                ]);

                // Transport exceptions can retain an authenticated request; do not report it.
                report(new Exception('Failed to autoscale workers ('.$exception::class.', HTTP '.($httpStatus ?? 'unavailable').')'));

                return;
            }

            Cache::put($cacheKey, $quantity, now()->addSeconds((int) config('queue-autoscaler.cache_ttl_seconds', 3600)));
            Cache::forever($cacheKey.':settled', $quantity);
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

    /**
     * Create a Heroku client with finite connection and total request timeouts.
     *
     * @param  string  $apiKey  The Heroku API credential.
     * @return Client The configured Heroku client.
     */
    protected function createClient(string $apiKey): Client
    {
        return new Client([
            'apiKey' => $apiKey,
            'curlOptions' => [
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            ],
        ]);
    }

    /**
     * Namespace formation state by application, including when caches are shared.
     *
     * @param  string  $appName  The Heroku application name.
     * @return string The application-specific cache key.
     */
    private function cacheKey(string $appName): string
    {
        return (string) config('queue-autoscaler.cache_key', 'queue-autoscaler:current-dynos').':'.sha1($appName);
    }
}
