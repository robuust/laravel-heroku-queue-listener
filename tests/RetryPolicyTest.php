<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Robuust\HerokuQueueListener\RetryPolicy;

class RetryPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('backoffCases')]
    public function test_exponential_backoff_has_bounded_jitter(int $failures, int $minimum, int $maximum): void
    {
        $delay = (new RetryPolicy)->delay($failures, null);

        self::assertGreaterThanOrEqual($minimum, $delay);
        self::assertLessThanOrEqual($maximum, $delay);
    }

    public static function backoffCases(): array
    {
        return [[1, 30, 37], [2, 60, 75], [3, 120, 150], [4, 240, 300], [5, 300, 300], [100, 300, 300]];
    }

    #[DataProvider('retryAfterCases')]
    public function test_retry_after_is_honored_or_invalid_values_fall_back(string $header, int $minimum, int $maximum): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00 UTC'));

        $delay = (new RetryPolicy)->delay(1, new Response(429, ['Retry-After' => $header]));

        self::assertGreaterThanOrEqual($minimum, $delay);
        self::assertLessThanOrEqual($maximum, $delay);
    }

    public static function retryAfterCases(): array
    {
        return [
            'seconds beyond local cap' => ['900', 900, 900],
            'HTTP date' => ['Tue, 03 Nov 2026 12:15:00 GMT', 900, 900],
            'obsolete RFC 850 date' => ['Tuesday, 03-Nov-26 12:15:00 GMT', 900, 900],
            'obsolete asctime date' => ['Tue Nov 3 12:15:00 2026', 900, 900],
            'zero does not shorten backoff' => ['0', 30, 37],
            'past date' => ['Tue, 03 Nov 2026 11:00:00 GMT', 30, 37],
            'empty' => ['', 30, 37],
            'invalid' => ['tomorrow', 30, 37],
            'negative' => ['-5', 30, 37],
            'invalid date' => ['Tue, 40 Nov 2026 12:15:00 GMT', 30, 37],
        ];
    }
}
