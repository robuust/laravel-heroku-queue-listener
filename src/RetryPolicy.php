<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;

/**
 * Calculates exponential cooldowns while respecting optional server retry guidance.
 */
class RetryPolicy
{
    /**
     * Choose a jittered 30–300 second cooldown or a longer valid Retry-After.
     *
     * @param  int  $failures  The number of consecutive failed attempts.
     * @param  ResponseInterface|null  $response  The most recent Heroku response, if received.
     * @return int The minimum delay before another request, in seconds.
     */
    public function delay(int $failures, ?ResponseInterface $response): int
    {
        $backoff = min(300, 30 * 2 ** min(max(0, $failures - 1), 4));
        $backoff = min(300, $backoff + random_int(0, (int) ($backoff / 4)));
        $retryAfter = trim($response?->getHeaderLine('Retry-After') ?? '');

        if (ctype_digit($retryAfter)) {
            return max($backoff, min((int) $retryAfter, PHP_INT_MAX - now()->getTimestamp()));
        }

        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $retryAfter, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return max($backoff, $date->getTimestamp() - now()->getTimestamp());
            }
        }

        return $backoff;
    }
}
