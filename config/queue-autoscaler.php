<?php

return [
    'heroku_api_key' => env('HEROKU_API_KEY'),
    'heroku_app_name' => env('HEROKU_APP_NAME'),

    // Optionally listen to an application release event and scale up only when jobs exist.
    'release_event' => env('QUEUE_AUTOSCALER_RELEASE_EVENT', 'App\\Events\\AppReleased'),

    // Scaling mode: "default" uses Queue::size(), "timeframe" uses due/reserved jobs within timeframe_minutes.
    'mode' => env('QUEUE_AUTOSCALER_MODE', 'default'),

    // Time window in minutes used to detect due/reserved jobs for scale-down and heartbeat dispatching. Only applies if mode is set to "timeframe".
    'timeframe_minutes' => (int) env('QUEUE_AUTOSCALER_TIMEFRAME_MINUTES', 2),

    'cache_key' => env('QUEUE_AUTOSCALER_CACHE_KEY', 'queue-autoscaler:current-dynos'),
    'cache_ttl_seconds' => (int) env('QUEUE_AUTOSCALER_CACHE_TTL_SECONDS', 3600),
];
