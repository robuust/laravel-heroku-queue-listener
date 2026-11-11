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

For Heroku Control, set the following environment variables:

- `HEROKU_CONTROL_URL` (the HTTPS gateway base URL)
- `HEROKU_CONTROL_TOKEN` (the app or review-pipeline worker credential)
- `HEROKU_APP_NAME`

The gateway URL and token must be configured together. Partial or invalid gateway
configuration raises a configuration error and never falls back to a legacy
credential. Redirects are disabled for gateway requests. Only the worker
formation endpoint is used; this package does not request deployment permission.

Existing applications can continue to use `HEROKU_API_KEY` and `HEROKU_APP_NAME`
when neither gateway setting is configured. Gateway tokens must not be placed in
`HEROKU_API_KEY`. To migrate, provision a scoped worker credential outside the
application, configure the gateway pair, and remove `HEROKU_API_KEY` from that
application after the gateway path is verified. Do not revoke a shared legacy
credential while other applications still use it.

Laravel merges the new `heroku_control_url` and `heroku_control_token` defaults
even when an application has an older published configuration file. Republishing
is optional; add those keys from the package's config if you manage them there.
Rebuild Laravel's configuration cache after updating the package and settings
with `php artisan config:cache`. The listener reads Laravel configuration and
does not access environment variables directly.

Optional:

- `QUEUE_AUTOSCALER_RELEASE_EVENT` (defaults to `App\\Events\\AppReleased`)
- `QUEUE_AUTOSCALER_CACHE_KEY` (defaults to `queue-autoscaler:current-dynos`)
- `QUEUE_AUTOSCALER_CACHE_TTL_SECONDS` (defaults to `3600`)

Publish config if needed:

```bash
php artisan vendor:publish --tag=queue-autoscaler-config
```

## HTTP failures and retries

Each scaling attempt has a 2-second connection timeout. Direct Heroku requests
have a 5-second total timeout; gateway requests have a 35-second total timeout,
longer than the gateway's 30-second execution limit. The cache lock expires five
seconds after the selected request timeout. Redirects are treated as failed
requests. There are no sleeps or immediate HTTP retries in the queue event
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

The listener requests one worker when jobs are queued and zero when the queue is
empty, regardless of queue depth.

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
