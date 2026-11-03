<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Tests;

use ArrayObject;
use Closure;
use GuzzleHttp\Psr7\Response;
use HerokuClient\Client;
use Http\Client\Exception\NetworkException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use Robuust\HerokuQueueListener\QueueAutoscalerListener;
use Robuust\HerokuQueueListener\QueueAutoscalerServiceProvider;
use RuntimeException;
use Throwable;

class QueueAutoscalerListenerTest extends TestCase
{
    private Container $container;

    private ConfigRepository $config;

    private CacheRepository $cache;

    private ArrayObject $reports;

    private array $warnings = [];

    private FakeHttpClient $http;

    private TestListener $listener;

    private Worker $worker;

    private int $queueSize = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00 UTC'));
        $this->container = new Container;
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);

        $this->config = new ConfigRepository(['queue-autoscaler' => [
            'heroku_api_key' => 'test-secret',
            'heroku_app_name' => 'test-app',
        ]]);
        $this->cache = new CacheRepository(new ArrayStore);
        $this->reports = new ArrayObject;
        $this->container->instance('config', $this->config);
        $this->container->instance('cache', $this->cache);
        $this->container->instance('test.reports', $this->reports);
        $this->worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $this->container->instance('queue.worker', $this->worker);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
            $this->warnings[] = $context;
        });
        $this->container->instance('log', $logger);

        $queue = $this->createMock(QueueContract::class);
        $queue->method('size')->willReturnCallback(fn (): int => $this->queueSize);
        $this->container->instance('queue', $queue);

        $this->http = new FakeHttpClient;
        $this->listener = new TestListener(new Client(['apiKey' => 'test-secret', 'httpClient' => $this->http]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_success_is_cached_and_idle_scheduler_does_not_repeat_requests(): void
    {
        $this->listener->scale(1);
        $this->listener->scale(1);
        Carbon::setTestNow(now()->addHours(2));
        $this->listener->retryPending();

        self::assertCount(1, $this->http->requests);
        self::assertSame('PATCH', $this->http->requests[0]->getMethod());
        self::assertSame('/apps/test-app/formation/worker', $this->http->requests[0]->getUri()->getPath());
        self::assertSame('{"quantity":1}', (string) $this->http->requests[0]->getBody());
        self::assertCount(0, $this->reports);
    }

    #[DataProvider('transientStatuses')]
    public function test_transient_failure_defers_one_retry_and_coalesces_repeated_events(int $httpStatus): void
    {
        $this->http->results = [new Response($httpStatus, ['RateLimit-Remaining' => '0', 'Request-Id' => 'request-123'], '{"id":"rate_limit"}')];
        $this->listener->scale(1);

        for ($event = 0; $event < 100; $event++) {
            $this->listener->scale(1);
        }
        $this->listener->retryPending();

        self::assertCount(1, $this->http->requests);
        self::assertNull($this->cache->get($this->key()));
        self::assertSame(1, $this->cache->get($this->key().':retry')['failures']);
        self::assertSame($httpStatus, $this->warnings[0]['http_status']);
        self::assertSame('0', $this->warnings[0]['rate_limit_remaining']);
        self::assertSame('request-123', $this->warnings[0]['request_id']);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->listener->retryPending();

        self::assertCount(2, $this->http->requests);
        self::assertSame(1, $this->cache->get($this->key()));
        self::assertNull($this->cache->get($this->key().':retry'));
    }

    public static function transientStatuses(): array
    {
        return [[408], [429], [500], [502], [503], [504]];
    }

    public function test_long_retry_after_applies_to_events_and_scheduler(): void
    {
        $this->http->results = [new Response(429, ['Retry-After' => '900'], '{}')];
        $this->listener->scale(1);
        Carbon::setTestNow(now()->addSeconds(899));
        $this->listener->scale(1);
        $this->listener->retryPending();
        self::assertCount(1, $this->http->requests);

        Carbon::setTestNow(now()->addSecond());
        $this->listener->retryPending();
        self::assertCount(2, $this->http->requests);
    }

    public function test_backoff_increases_across_listener_instances_and_resets_after_success(): void
    {
        $this->http->results = array_fill(0, 6, new Response(503, [], '{}'));
        $minimums = [30, 60, 120, 240, 300, 300];

        $this->listener->scale(1);
        foreach ($minimums as $index => $minimum) {
            $retry = $this->cache->get($this->key().':retry');
            self::assertSame(min($index + 1, 5), $retry['failures']);
            self::assertGreaterThanOrEqual($minimum, $retry['after'] - now()->getTimestamp());
            self::assertLessThanOrEqual(min(300, (int) ($minimum * 1.25)), $retry['after'] - now()->getTimestamp());

            Carbon::setTestNow(Carbon::createFromTimestampUTC($retry['after']));
            $listener = new TestListener(new Client(['apiKey' => 'test-secret', 'httpClient' => $this->http]));
            $listener->retryPending();
        }

        self::assertCount(7, $this->http->requests);
        self::assertNull($this->cache->get($this->key().':retry'));
        $this->http->results = [new Response(503, [], '{}')];
        $this->listener->scale(0);
        self::assertSame(1, $this->cache->get($this->key().':retry')['failures']);
    }

    public function test_lock_contention_returns_immediately_and_scheduler_applies_latest_desire(): void
    {
        $lock = $this->cache->lock($this->key().':lock', 10);
        self::assertTrue($lock->get());
        $this->listener->scale(1);
        $this->listener->scale(0);
        self::assertCount(0, $this->http->requests);

        $lock->release();
        $this->listener->retryPending();
        self::assertCount(1, $this->http->requests);
        self::assertSame('{"quantity":0}', (string) $this->http->requests[0]->getBody());
    }

    public function test_event_during_an_inflight_request_is_not_lost(): void
    {
        $this->http->results = [function (): ResponseInterface {
            $this->listener->scale(0);
            self::assertCount(1, $this->http->requests);

            return new Response(200, [], '{}');
        }];
        $this->listener->scale(1);
        $this->listener->retryPending();

        self::assertCount(2, $this->http->requests);
        self::assertSame('{"quantity":0}', (string) $this->http->requests[1]->getBody());
        self::assertSame(0, $this->cache->get($this->key()));
    }

    public function test_timeout_invalidates_old_count_and_does_not_expose_authenticated_request(): void
    {
        $this->listener->scale(1);
        $this->http->results = [fn (RequestInterface $request): never => throw new NetworkException('transport test-secret', $request)];
        $this->listener->scale(0);
        self::assertNull($this->cache->get($this->key()));
        self::assertNull($this->reports[0]->getPrevious());
        self::assertStringNotContainsString('test-secret', $this->reports[0]->getMessage());
        self::assertStringNotContainsString('test-secret', json_encode($this->warnings));

        $this->listener->scale(1);
        Carbon::setTestNow(now()->addMinutes(6));
        $this->listener->retryPending();
        self::assertCount(3, $this->http->requests);
        self::assertSame('{"quantity":1}', (string) $this->http->requests[2]->getBody());
    }

    #[DataProvider('permanentStatuses')]
    public function test_permanent_errors_are_not_automatically_retried(int $httpStatus): void
    {
        $this->http->results = [new Response($httpStatus, [], '{}')];
        $this->listener->scale(1);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->listener->retryPending();
        self::assertCount(1, $this->http->requests);
        self::assertNull($this->warnings[0]['retry_in_seconds']);

        $this->listener->scale(1);
        self::assertCount(2, $this->http->requests);
    }

    public static function permanentStatuses(): array
    {
        return [[400], [401], [403], [404], [422]];
    }

    public function test_application_state_is_isolated_in_a_shared_cache(): void
    {
        $this->http->results = [new Response(429, ['Retry-After' => '900'], '{}')];
        $this->listener->scale(1);
        $this->config->set('queue-autoscaler.heroku_app_name', 'other-app');
        $this->listener->scale(1);

        self::assertCount(2, $this->http->requests);
        self::assertSame('/apps/other-app/formation/worker', $this->http->requests[1]->getUri()->getPath());
    }

    public function test_queue_events_keep_existing_scale_up_and_scale_down_behavior(): void
    {
        $this->listener->handle((new ReflectionClass(JobQueued::class))->newInstanceWithoutConstructor());
        $this->queueSize = 1;
        $this->listener->handle((new ReflectionClass(JobProcessed::class))->newInstanceWithoutConstructor());
        self::assertCount(1, $this->http->requests);
        self::assertFalse($this->worker->shouldQuit);

        $this->queueSize = 0;
        $this->listener->handle((new ReflectionClass(JobProcessed::class))->newInstanceWithoutConstructor());
        self::assertCount(2, $this->http->requests);
        self::assertSame('{"quantity":0}', (string) $this->http->requests[1]->getBody());
        self::assertTrue($this->worker->shouldQuit);
    }

    public function test_failed_job_stops_idle_worker_even_when_scaling_times_out(): void
    {
        $this->http->results = [function (RequestInterface $request): never {
            self::assertTrue($this->worker->shouldQuit);

            throw new NetworkException('timed out', $request);
        }];

        $this->listener->handle((new ReflectionClass(JobFailed::class))->newInstanceWithoutConstructor());

        self::assertTrue($this->worker->shouldQuit);
        self::assertCount(1, $this->http->requests);
        Carbon::setTestNow(now()->addMinutes(6));
        $this->listener->retryPending();
        self::assertSame(0, $this->cache->get($this->key()));
    }

    public function test_release_event_scales_for_queue_size_without_stopping_a_worker(): void
    {
        $this->config->set('queue-autoscaler.release_event', \stdClass::class);
        $this->queueSize = 1;
        $this->listener->handle(new \stdClass);
        $this->queueSize = 0;
        $this->listener->handle(new \stdClass);

        self::assertCount(2, $this->http->requests);
        self::assertSame(0, $this->cache->get($this->key()));
        self::assertFalse($this->worker->shouldQuit);
    }

    public function test_missing_credentials_disable_events_and_scheduled_retries(): void
    {
        $this->config->set('queue-autoscaler.heroku_api_key', '');
        $this->listener->handle((new ReflectionClass(JobQueued::class))->newInstanceWithoutConstructor());
        $this->listener->retryPending();

        self::assertCount(0, $this->http->requests);
    }

    public function test_scheduler_callback_can_restart_workers_without_a_running_worker(): void
    {
        $this->http->results = [fn (RequestInterface $request): never => throw new NetworkException('timed out', $request)];
        $this->listener->scale(1);
        $this->container->instance(QueueAutoscalerListener::class, $this->listener);
        $this->container->instance(EventMutex::class, $this->createMock(EventMutex::class));
        $this->container->instance(SchedulingMutex::class, $this->createMock(SchedulingMutex::class));

        $schedule = new Schedule;
        (new QueueAutoscalerServiceProvider($this->container))->registerSchedule($schedule);
        self::assertCount(1, $schedule->events());
        $event = $schedule->events()[0];
        self::assertSame('* * * * *', $event->expression);
        self::assertSame('queue-autoscaler:retry', $event->description);

        Carbon::setTestNow(now()->addMinutes(6));
        $event->run($this->container);
        self::assertCount(2, $this->http->requests);
        self::assertSame(1, $this->cache->get($this->key()));
    }

    public function test_production_client_has_bounded_connection_and_total_timeouts(): void
    {
        $client = $this->listener->productionClient();
        $options = (new ReflectionProperty(Client::class, 'curlOptions'))->getValue($client);

        self::assertSame(2, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(5, $options[CURLOPT_TIMEOUT]);
    }

    public function test_cache_write_failure_after_success_does_not_fail_the_queue_job(): void
    {
        $cache = $this->getMockBuilder(CacheRepository::class)
            ->setConstructorArgs([new ArrayStore])
            ->onlyMethods(['put'])
            ->getMock();
        $cache->method('put')->willThrowException(new RuntimeException('Cache unavailable'));
        $this->container->instance('cache', $cache);
        Facade::clearResolvedInstance('cache');

        $this->listener->scale(1);

        self::assertCount(1, $this->http->requests);
        self::assertCount(1, $this->reports);
        self::assertNull($this->reports[0]->getPrevious());
        $lock = $cache->lock($this->key().':lock', 10);
        self::assertTrue($lock->get());
        $lock->release();
    }

    private function key(): string
    {
        return 'queue-autoscaler:current-dynos:'.sha1('test-app');
    }
}

class TestListener extends QueueAutoscalerListener
{
    public function __construct(private Client $client) {}

    public function scale(int $quantity): void
    {
        $this->scaleWorkers(config('queue-autoscaler.heroku_app_name'), 'test-secret', $quantity);
    }

    public function productionClient(): Client
    {
        return parent::createClient('test-secret');
    }

    protected function createClient(string $apiKey): Client
    {
        return $this->client;
    }
}

class FakeHttpClient implements ClientInterface
{
    public array $requests = [];

    public array $results = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $result = array_shift($this->results) ?? new Response(200, [], '{}');

        if ($result instanceof Closure) {
            $result = $result($request);
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
