<?php

declare(strict_types=1);

return [
    // JSON object keyed by workspace UUID. Values are server-side Airtable PATs.
    // Scope each PAT to the selected base and data.records:read/write only.
    // Never send this config to Inertia, logs, Airtable cells or the browser.
    'tokens' => json_decode((string) env('AIRTABLE_WORKSPACE_TOKENS', '{}'), true) ?: [],
    'monthly_call_budget' => (int) env('AIRTABLE_MONTHLY_CALL_BUDGET', 800),
    'max_records' => 1000,
    'max_calls_per_sync' => 50,
];
