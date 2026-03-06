# Laravel Heroku Queue Listener

Autoscale Heroku `worker` dynos based on Laravel queue events.

## Installation

```bash
composer require robuust/laravel-heroku-queue-listener
```

The package uses Laravel package auto-discovery.

## Configuration

Set the following environment variables:

- `HEROKU_API_KEY`
- `HEROKU_APP_NAME`

Optional:

- `QUEUE_AUTOSCALER_RELEASE_EVENT` (defaults to `App\\Events\\AppReleased`)
- `QUEUE_AUTOSCALER_MODE` (defaults to `default`)
- `QUEUE_AUTOSCALER_TIMEFRAME_MINUTES` (defaults to `2`)
- `QUEUE_AUTOSCALER_CACHE_KEY` (defaults to `queue-autoscaler:current-dynos`)
- `QUEUE_AUTOSCALER_CACHE_TTL_SECONDS` (defaults to `3600`)

Publish config if needed:

```bash
php artisan vendor:publish --tag=queue-autoscaler-config
```

### Scaling Modes

- `default`: scales down only when `Queue::size() === 0`.
- `timeframe`: scales down only when there are no due/reserved jobs available. Can look a few minutes in the future to determine this using `QUEUE_AUTOSCALER_TIMEFRAME_MINUTES`.

## Heartbeat

For delayed/backoff jobs, you can periodically dispatch a heartbeat pulse so Heroku workers wake up on demand:

```bash
php artisan workers:dispatch-heartbeat
```

The command checks for due/reserved jobs within `QUEUE_AUTOSCALER_TIMEFRAME_MINUTES`. If none are found, it will not dispatch a heartbeat job.
For timeframe-based autoscaling, set `QUEUE_AUTOSCALER_MODE=timeframe`.

Typical scheduler usage:

```php
Schedule::command('workers:dispatch-heartbeat')->everyMinute();
```
