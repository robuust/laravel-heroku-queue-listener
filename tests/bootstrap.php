<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;

require __DIR__.'/../vendor/autoload.php';

/**
 * Resolve Laravel services without booting a consuming application.
 *
 * @param  string  $key  The container service name.
 * @return mixed The resolved service.
 */
function app(string $key): mixed
{
    return Container::getInstance()->make($key);
}

/**
 * Provide Laravel's configuration helper without booting a consuming application.
 *
 * @param  string  $key  The configuration key.
 * @param  mixed  $default  The value returned for a missing key.
 * @return mixed The configured value.
 */
function config(string $key, mixed $default = null): mixed
{
    return Container::getInstance()->make('config')->get($key, $default);
}

/**
 * Provide Laravel's clock helper for deterministic cooldown assertions.
 *
 * @return Carbon The current test time.
 */
function now(): Carbon
{
    return Carbon::now();
}

/**
 * Capture reports without installing a full Laravel application exception handler.
 *
 * @param  Throwable  $exception  The reported exception.
 */
function report(Throwable $exception): void
{
    Container::getInstance()->make('test.reports')->append($exception);
}
