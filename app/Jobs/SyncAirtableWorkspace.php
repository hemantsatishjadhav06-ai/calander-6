<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AirtableIntegration;
use App\Services\Airtable\AirtableSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncAirtableWorkspace implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 700;

    public int $uniqueFor = 900;

    public function __construct(public string $integrationId) {}

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function handle(AirtableSyncService $sync): void
    {
        $integration = AirtableIntegration::query()->find($this->integrationId);
        if ($integration) {
            $sync->sync($integration);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AirtableIntegration::query()->whereKey($this->integrationId)->update([
            'last_error' => 'The sync worker stopped before completion. Saved progress can be retried after its lease expires.',
        ]);
    }
}
