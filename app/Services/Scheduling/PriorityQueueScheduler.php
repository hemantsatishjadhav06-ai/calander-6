<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Enums\PostStatus;
use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\Post;
use App\Models\RecurringPostOccurrence;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\NextSlotResolver;
use App\Services\Posts\PostReviewService;
use App\Services\Posts\PublishPrecheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PriorityQueueScheduler
{
    public function __construct(
        private readonly NextSlotResolver $slots,
        private readonly PostReviewService $reviews,
        private readonly PublishPrecheck $precheck,
        private readonly EditorialPublishingGuard $guard,
        private readonly WorkspaceSubscriptionGate $subscriptions,
    ) {}

    public function enqueue(EditorialQueue $queue, Post $post, int $priority): EditorialQueueEntry
    {
        return DB::transaction(function () use ($queue, $post, $priority): EditorialQueueEntry {
            Workspace::query()->whereKey($queue->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = EditorialQueue::withoutGlobalScopes()->lockForUpdate()->findOrFail($queue->id);
            $post = Post::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->lockForUpdate()->findOrFail($post->id);
            if ($locked->state === 'stopped' || $post->status !== PostStatus::Draft) {
                throw ValidationException::withMessages(['post_id' => 'Choose a draft and a queue that has not been stopped.']);
            }
            $existing = EditorialQueueEntry::withoutGlobalScopes()->where('post_id', $post->id)->first();
            if ($existing !== null && $existing->editorial_queue_id !== $locked->id && $existing->status !== 'cancelled') {
                throw ValidationException::withMessages(['post_id' => 'This post already belongs to another priority queue. Remove it there first.']);
            }

            return EditorialQueueEntry::withoutGlobalScopes()->updateOrCreate(['post_id' => $post->id], [
                'workspace_id' => $locked->workspace_id, 'editorial_queue_id' => $locked->id,
                'priority' => $priority, 'status' => 'waiting', 'blocked_reason' => null, 'scheduled_at' => null,
            ]);
        });
    }

    public function fill(Workspace $workspace): int
    {
        return DB::transaction(function () use ($workspace): int {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $this->reconcile($workspace);
            if (! $this->subscriptions->canPublish($workspace)) {
                return 0;
            }
            $queues = EditorialQueue::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('state', 'active')->orderByDesc('priority')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $available = $this->slots->availableSlots($workspace);
            $scheduled = 0;
            foreach ($queues as $queue) {
                $entries = EditorialQueueEntry::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('editorial_queue_id', $queue->id)->where('status', 'waiting')->orderByDesc('priority')->orderBy('created_at')->orderBy('id')->lockForUpdate()->cursor();
                foreach ($entries as $entry) {
                    $post = Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->lockForUpdate()->find($entry->post_id);
                    if ($post === null || $post->status === PostStatus::Deleted) {
                        $entry->forceFill(['status' => 'cancelled', 'blocked_reason' => 'The post is no longer available.'])->save();

                        continue;
                    }
                    if ($post->status !== PostStatus::Draft) {
                        $entry->forceFill(['status' => $post->status->value, 'scheduled_at' => $post->scheduled_at, 'blocked_reason' => null])->save();

                        continue;
                    }
                    if (! $this->guard->allows($post) || ! $this->reviews->canPublish($post)) {
                        $entry->forceFill(['blocked_reason' => 'Awaiting current approval, fresh design exports, or an active recurring series.'])->save();

                        continue;
                    }
                    if ($post->targets()->count() === 0 || $this->precheck->blockingTargets($post) !== []) {
                        $entry->forceFill(['blocked_reason' => 'Open the post and resolve its publishing checks.'])->save();

                        continue;
                    }
                    $intendedAt = RecurringPostOccurrence::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('post_id', $post->id)->first()?->intended_at;
                    $slot = null;
                    foreach ($available as $index => $candidate) {
                        if ($intendedAt === null || $candidate->greaterThanOrEqualTo($intendedAt)) {
                            $slot = $candidate;
                            array_splice($available, $index, 1);
                            break;
                        }
                    }
                    if ($slot === null) {
                        $entry->forceFill(['blocked_reason' => 'No open posting-schedule slot at or after this occurrence. Add slots or wait for availability.'])->save();

                        continue;
                    }
                    $post->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => $slot])->save();
                    $entry->forceFill(['status' => 'scheduled', 'scheduled_at' => $slot, 'blocked_reason' => null])->save();
                    $scheduled++;
                }
            }

            return $scheduled;
        });
    }

    private function reconcile(Workspace $workspace): void
    {
        $entries = EditorialQueueEntry::withoutGlobalScopes()->where('workspace_id', $workspace->id)->whereIn('status', ['scheduled', 'publishing'])->lockForUpdate()->get();
        $posts = Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->whereIn('id', $entries->pluck('post_id'))->get()->keyBy('id');
        foreach ($entries as $entry) {
            $post = $posts->get($entry->post_id);
            if ($post === null || in_array($post->status, [PostStatus::Draft, PostStatus::Deleted], true)) {
                $entry->forceFill(['status' => 'cancelled', 'scheduled_at' => null, 'blocked_reason' => 'The post was removed from the publishing schedule.'])->save();
            } elseif ($post->status !== PostStatus::Scheduled) {
                $entry->forceFill(['status' => $post->status->value])->save();
            }
        }
    }

    public function transition(EditorialQueue $queue, string $state, int $revision): void
    {
        DB::transaction(function () use ($queue, $state, $revision): void {
            Workspace::query()->whereKey($queue->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = EditorialQueue::withoutGlobalScopes()->lockForUpdate()->findOrFail($queue->id);
            abort_unless($locked->revision === $revision, 409, 'The queue changed. Reload before trying again.');
            if ($locked->state === 'stopped') {
                throw ValidationException::withMessages(['state' => 'A stopped queue cannot be restarted. Create a new queue instead.']);
            }
            if ($state !== 'active') {
                $ids = EditorialQueueEntry::withoutGlobalScopes()->where('editorial_queue_id', $locked->id)->where('status', '!=', 'cancelled')->pluck('post_id');
                Post::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->whereIn('id', $ids)->where('status', PostStatus::Scheduled)->update(['status' => PostStatus::Draft, 'scheduled_at' => null]);
                EditorialQueueEntry::withoutGlobalScopes()->where('editorial_queue_id', $locked->id)->whereIn('status', ['waiting', 'scheduled'])->update(['status' => 'waiting', 'scheduled_at' => null, 'blocked_reason' => 'The queue is '.$state.'.']);
            }
            $locked->forceFill(['state' => $state, 'revision' => $locked->revision + 1])->save();
        });
    }

    public function remove(EditorialQueueEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            Workspace::query()->whereKey($entry->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = EditorialQueueEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);
            $post = Post::withoutGlobalScopes()->where('workspace_id', $locked->workspace_id)->lockForUpdate()->find($locked->post_id);
            if ($post !== null && $post->status === PostStatus::Publishing) {
                throw ValidationException::withMessages(['entry' => 'This post is already publishing and cannot be removed now.']);
            }
            if ($post !== null && $post->status === PostStatus::Scheduled) {
                $post->forceFill(['status' => PostStatus::Draft, 'scheduled_at' => null])->save();
            }
            $locked->forceFill(['status' => 'cancelled', 'scheduled_at' => null, 'blocked_reason' => null])->save();
        });
    }
}
