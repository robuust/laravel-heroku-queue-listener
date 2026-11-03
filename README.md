# Laravel Heroku Queue Listener

Autoscale Heroku `worker` dynos based on Laravel queue events.

## Installation

```bash
composer require robuust/laravel-heroku-queue-listener
```

The package uses Laravel package auto-discovery.

PCNTL is required for Laravel's `queue:work` daemon to handle POSIX signals and enforce job timeouts. Heroku's PHP buildpack enables extensions required by dependencies from the application's committed `composer.lock` on the next build.

If a shell script launches the worker, use `exec php artisan queue:work` as its final command to replace the shell with PHP. Heroku sends `SIGTERM` to all processes in a dyno; `exec` removes the extra shell process.

## Configuration

Set the following environment variables:

- `HEROKU_API_KEY`
- `HEROKU_APP_NAME`

Optional:

- `QUEUE_AUTOSCALER_RELEASE_EVENT` (defaults to `App\\Events\\AppReleased`)
- `QUEUE_AUTOSCALER_CACHE_KEY` (defaults to `queue-autoscaler:current-dynos`)
- `QUEUE_AUTOSCALER_CACHE_TTL_SECONDS` (defaults to `3600`)

Publish config if needed:

```bash
php artisan vendor:publish --tag=queue-autoscaler-config
```

## HTTP failures and retries

Each scaling attempt has a 2-second connection timeout and a 5-second total
timeout. There are no sleeps or immediate HTTP retries in the queue event
listener. A non-blocking cache lock allows only one scaling request per app at a
time. Each event requests its own worker count; concurrent events that cannot
acquire the lock return immediately without queuing another request.

Failed requests set a shared exponential cooldown (30, 60, 120, 240, then
300 seconds, with up to 25% jitter, capped at
300 seconds). A valid `Retry-After` in seconds or HTTP-date format can extend
that cooldown beyond 300 seconds. Success clears the failure count. A later
queue or configured application release event may try again after the cooldown.
A failed request invalidates the cached worker count because a timed-out PATCH
may already have reached Heroku.

The package registers no scheduled tasks and starts no background retry process.
It makes requests only in response to existing queue or application release
events. Cooldown expiry alone does not trigger a request. If a worker startup
fails and no subsequent event occurs after the cooldown, queued work remains
waiting until another event retries the scaling request. Likewise, a request
skipped because another request holds the lock requires a subsequent event.

Use a shared persistent cache supporting atomic locks (for example Redis) across
all application dynos. Local file or array caches do not coordinate requests
between dynos. State keys are scoped by Heroku app name,
under the configured cache key; the previous unscoped cached count is ignored.

Failures log the HTTP status, `Request-Id`, `RateLimit-Remaining`, `Retry-After`
and the retry delay when a response is available. Authenticated requests and
raw exception messages are not included. A timeout by itself does not establish
rate limiting: Heroku documents that as HTTP 429 with the `rate_limit` error ID.
The Platform API documents `RateLimit-Remaining`, but does not promise a
`Retry-After` or rate-limit reset header, so retries also work without them.

References: [Heroku Platform API](https://devcenter.heroku.com/articles/platform-api-reference#rate-limits)
and [HTTP Retry-After](https://www.rfc-editor.org/rfc/rfc9110.html#name-retry-after).

## Development

Install dependencies with `composer install`. Run `composer lint` for PHP style
checks and `composer test` for the regression suite. Tests use a fake HTTP
transport and an in-memory cache; they do not contact Heroku. This repository
has no CI coverage threshold configured.
