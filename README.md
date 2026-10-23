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
