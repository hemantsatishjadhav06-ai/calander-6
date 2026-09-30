<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\Post;
use App\Models\RecurringPostOccurrence;
use App\Models\RecurringPostSeries;

final class EditorialPublishingGuard
{
    public function allows(Post $post): bool
    {
        $occurrence = RecurringPostOccurrence::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->first();
        if ($occurrence?->status === 'skipped') {
            return false;
        }
        if ($occurrence !== null && RecurringPostSeries::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->whereKey($occurrence->recurring_post_series_id)->whereNotIn('state', ['active', 'completed'])->exists()) {
            return false;
        }

        $entry = EditorialQueueEntry::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->where('status', '!=', 'cancelled')->first();

        return $entry === null || ! EditorialQueue::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->whereKey($entry->editorial_queue_id)->where('state', '!=', 'active')->exists();
    }
}
