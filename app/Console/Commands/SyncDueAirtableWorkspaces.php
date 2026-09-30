<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncAirtableWorkspace;
use App\Models\AirtableIntegration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Queue due opt-in Airtable syncs (manual is the default)')]
#[Signature('airtable:sync-due')]
class SyncDueAirtableWorkspaces extends Command
{
    public function handle(): int
    {
        AirtableIntegration::query()->where('enabled', true)->where('sync_interval_minutes', '>=', 360)
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()))
            ->each(fn (AirtableIntegration $integration) => SyncAirtableWorkspace::dispatch($integration->id));

        return self::SUCCESS;
    }
}
