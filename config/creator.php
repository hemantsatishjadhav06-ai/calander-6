<?php

declare(strict_types=1);

return [
    'fal' => [
        'enabled' => (bool) env('CREATOR_FAL_ENABLED', false),
        'key' => env('FAL_KEY'),
        'quote_ttl_seconds' => 300,
        'http_timeout_seconds' => 30,
        'poll_interval_seconds' => 3,
    ],
];
