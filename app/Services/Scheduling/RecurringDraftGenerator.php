<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Enums\PostStatus;
use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\Post;
use App\Models\RecurringPostOccurrence;
use App\Models\RecurringPostSeries;
use App\Models\Workspace;
use App\Services\Creator\CreatorExportFreshness;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PostReviewService;
use App\Support\FileStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RecurringDraftGenerator
{
    public function __construct(
        private readonly RecurrenceCalendar $calendar,
        private readonly PostDuplicator $duplicator,
        private readonly PostReviewService $reviews,
        private readonly CreatorExportFreshness $freshness,
    ) {}

    public function generate(RecurringPostSeries $series): int
    {
        /** @var list<Post> $drafts */
        $drafts = [];

        try {
            return DB::transaction(function () use ($series, &$drafts): int {
                Workspace::query()->whereKey($series->workspace_id)->lockForUpdate()->firstOrFail();
                $locked = RecurringPostSeries::withoutGlobalScopes()->lockForUpdate()->findOrFail($series->id);
                if ($locked->state !== 'active') {
                    return 0;
                }
                $source = Post::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereKey($locked->source_post_id)->lockForUpdate()->first();
                if ($source === null || $source->status === PostStatus::Deleted) {
                    $locked->forceFill(['state' => 'held', 'last_error' => 'The source post is unavailable. Choose another source before resuming.'])->save();

                    return 0;
                }
                $source->load(['media', 'targets.placements']);
                if ($this->freshness->hasStaleExports($source)) {
                    $locked->forceFill(['last_error' => 'The source design changed. Export the current revision before generating more drafts.'])->save();

                    return 0;
                }
                $created = 0;
                $now = CarbonImmutable::now('UTC');
                $horizon = $now->addHours($locked->lead_hours);
                for ($i = 0; $i < 32; $i++) {
                    if (($locked->ends_on !== null && $locked->next_date > $locked->ends_on)
                        || ($locked->max_occurrences !== null && $locked->generated_count >= $locked->max_occurrences)) {
                        $locked->state = 'completed';
                        break;
                    }
                    $intended = $this->calendar->instant($locked);
                    if ($intended->greaterThan($horizon)) {
                        break;
                    }
                    $key = $locked->next_date;
                    $existing = RecurringPostOccurrence::withoutGlobalScopes()->where('recurring_post_series_id', $locked->id)->where('occurrence_key', $key)->exists();
                    if (! $existing) {
                        $draft = $this->duplicator->duplicate($source);
                        $drafts[] = $draft;
                        $draft->forceFill([
                            'status' => PostStatus::Draft,
                            'scheduled_at' => null,
                            'review_required' => true,
                            'review_status' => 'pending',
                            'review_revision' => $this->reviews->revision($draft),
                            'review_note' => null,
                        ])->save();
                        $this->reviews->record($draft, 'recurring_draft_generated', 'scheduling');
                        RecurringPostOccurrence::create([
                            'workspace_id' => $locked->workspace_id, 'recurring_post_series_id' => $locked->id,
                            'occurrence_key' => $key, 'intended_at' => $intended, 'post_id' => $draft->id, 'status' => 'generated',
                        ]);
                        if ($locked->editorial_queue_id !== null && EditorialQueue::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereKey($locked->editorial_queue_id)->where('state', '!=', 'stopped')->exists()) {
                            EditorialQueueEntry::create([
                                'workspace_id' => $locked->workspace_id, 'editorial_queue_id' => $locked->editorial_queue_id,
                                'post_id' => $draft->id, 'priority' => 50, 'status' => 'waiting', 'blocked_reason' => 'Awaiting review of this occurrence.',
                            ]);
                        }
                        $locked->generated_count++;
                        $created++;
                    }
                    $locked->next_date = $this->calendar->nextDate($locked);
                }
                $locked->last_error = null;
                $locked->save();

                return $created;
            });
        } catch (Throwable $exception) {
            foreach ($drafts as $draft) {
                foreach ($draft->media as $media) {
                    FileStorage::disk($media->disk)->delete($media->path);
                    if ($media->source_path !== null) {
                        FileStorage::disk($media->source_disk ?? $media->disk)->delete($media->source_path);
                    }
                }
            }

            throw $exception;
        }
    }

    public function transition(RecurringPostSeries $series, string $state, int $revision): void
    {
        DB::transaction(function () use ($series, $state, $revision): void {
            Workspace::query()->whereKey($series->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = RecurringPostSeries::withoutGlobalScopes()->lockForUpdate()->findOrFail($series->id);
            abort_unless($locked->revision === $revision, 409, 'The series changed. Reload before trying again.');
            if ($locked->state === 'stopped') {
                throw ValidationException::withMessages(['state' => 'A stopped series cannot be restarted. Create a new series instead.']);
            }
            if ($state === 'active' && $locked->state === 'paused') {
                $now = CarbonImmutable::now('UTC');
                $expired = RecurringPostOccurrence::withoutGlobalScopes()
                    ->where('recurring_post_series_id', $locked->id)
                    ->where('intended_at', '<=', $now)
                    ->whereIn('post_id', Post::withoutGlobalScopes()->select('id')->where('workspace_id', $locked->workspace_id)->whereIn('status', [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed, PostStatus::Missed]))
                    ->pluck('post_id');
                RecurringPostOccurrence::withoutGlobalScopes()->where('recurring_post_series_id', $locked->id)->whereIn('post_id', $expired)->update(['status' => 'skipped']);
                EditorialQueueEntry::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereIn('post_id', $expired)->update(['status' => 'cancelled', 'scheduled_at' => null, 'blocked_reason' => 'Occurrence skipped while the series was paused.']);
                Post::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereIn('id', $expired)->where('status', PostStatus::Scheduled)->update(['status' => PostStatus::Draft, 'scheduled_at' => null]);
                while ($this->calendar->instant($locked)->lessThanOrEqualTo($now)) {
                    $locked->next_date = $this->calendar->nextDate($locked);
                }
            }
            if ($state !== 'active') {
                $ids = RecurringPostOccurrence::withoutGlobalScopes()->where('recurring_post_series_id', $locked->id)->whereNotNull('post_id')->pluck('post_id');
                Post::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereIn('id', $ids)->where('status', PostStatus::Scheduled)->update(['status' => PostStatus::Draft, 'scheduled_at' => null]);
                EditorialQueueEntry::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereIn('post_id', $ids)->where('status', 'scheduled')->update(['status' => 'waiting', 'scheduled_at' => null, 'blocked_reason' => 'The recurring series is '.$state.'.']);
            }
            $locked->forceFill(['state' => $state, 'revision' => $locked->revision + 1])->save();
        });
    }
}
