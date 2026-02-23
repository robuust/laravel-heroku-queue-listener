<?php

return [
    'heroku_api_key' => env('HEROKU_API_KEY'),
    'heroku_app_name' => env('HEROKU_APP_NAME'),

    // Optionally listen to an application release event and scale up only when jobs exist.
    'release_event' => env('QUEUE_AUTOSCALER_RELEASE_EVENT', 'App\\Events\\AppReleased'),

    'cache_key' => env('QUEUE_AUTOSCALER_CACHE_KEY', 'queue-autoscaler:current-dynos'),
    'cache_ttl_seconds' => (int) env('QUEUE_AUTOSCALER_CACHE_TTL_SECONDS', 3600),
];
