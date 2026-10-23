# Laravel Heroku Queue Listener

Autoscale Heroku `worker` dynos based on Laravel queue events.

## Installation

```bash
composer require robuust/laravel-heroku-queue-listener
```

The package uses Laravel package auto-discovery.

PCNTL is required for Laravel's worker signal and timeout handling. Heroku enables the extension automatically from the package's Composer requirements. Start shell-based workers with `exec php artisan queue:work` so PHP receives stop signals directly.

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

## Releases

After a changelog change is merged into `main`, GitHub Actions publishes the first changelog version as a tag and GitHub release at that exact commit. Existing releases are skipped; existing tags without a release fail for manual reconciliation. The workflow can also be rerun manually on `main`.
