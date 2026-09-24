<?php

declare(strict_types=1);

return [
    // On by default, like engagement/metrics. Instance owners flip it at
    // Settings → Instance → Polling; the persisted toggle wins over this.
    'enabled' => (bool) env('MESSAGES_ENABLED', true),

    // Instance-level opt-in that DM OAuth scopes may be requested at connect/re-auth.
    'direct_messages_enabled' => (bool) env('DIRECT_MESSAGES_ENABLED', true),

    // Per-account poll interval floor (minutes), keyed by platform value.
    // X is billable per poll, so its floor is deliberately high.
    'poll_interval_minutes' => [
        'x' => (int) env('MESSAGES_POLL_INTERVAL_X', 30),
        'bluesky' => (int) env('MESSAGES_POLL_INTERVAL_BLUESKY', 10),
        'instagram' => (int) env('MESSAGES_POLL_INTERVAL_INSTAGRAM', 15),
        'facebook' => (int) env('MESSAGES_POLL_INTERVAL_FACEBOOK', 15),
    ],

    'default_rate_limit_backoff' => (int) env('MESSAGES_DEFAULT_RATE_LIMIT_BACKOFF', 900),

    // Park duration (seconds) when the platform reports the account's API quota
    // is spent (X: 402 "credits depleted"). That needs a plan change to clear,
    // so retrying on the normal interval just burns calls for nothing.
    'quota_exhausted_backoff' => (int) env('MESSAGES_QUOTA_EXHAUSTED_BACKOFF', 21600),
    'fetch_rate_per_minute' => (int) env('MESSAGES_FETCH_RATE_PER_MINUTE', 12),

    // Meta standard messaging window in hours.
    'meta_window_hours' => 24,
];
