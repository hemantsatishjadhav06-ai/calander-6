<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\Posts\PostApprovalService;
use App\Services\Publishing\PublishDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Description('Claim due scheduled posts and dispatch their per-target publish jobs.')]
#[Signature('posts:dispatch-due')]
class DispatchDuePosts extends Command
{
    public function handle(PublishDispatcher $dispatcher, PostApprovalService $approvals): int
    {
        $now = Date::now();
        $cutoff = $now->copy()->subMinutes((int) config('posts.missed_after_minutes'));

        // Posts overdue beyond the staleness window are never published late; mark
        // them missed in one idempotent bulk update (no dispatch, no claim race).
        Post::query()
            ->withoutGlobalScopes()
            ->where('status', PostStatus::Scheduled->value)
            ->where('scheduled_at', '<', $cutoff)
            ->update(['status' => PostStatus::Missed->value]);

        // Catch-up: claim posts due now and overdue by no more than the window.
        $candidateIds = Post::query()
            ->withoutGlobalScopes()
            ->where('status', PostStatus::Scheduled->value)
            ->whereBetween('scheduled_at', [$cutoff, $now])
            ->pluck('id')
            ->all();

        if ($candidateIds === []) {
            return self::SUCCESS;
        }

        foreach ($candidateIds as $id) {
            DB::transaction(function () use ($id, $dispatcher, $approvals, $cutoff, $now): void {
                $post = Post::query()->withoutGlobalScopes()->whereKey($id)
                    ->where('status', PostStatus::Scheduled->value)
                    ->whereBetween('scheduled_at', [$cutoff, $now])
                    ->lockForUpdate()->first();

                if ($post === null || $post->status !== PostStatus::Scheduled) {
                    return;
                }

                try {
                    $approvals->assertPlan($post, $post->scheduled_at);
                } catch (ValidationException) {
                    return;
                }

                // The default database queue writes its jobs in this transaction, so
                // an interrupted fan-out rolls back both the claim and queued jobs.
                $claimed = Post::query()
                    ->withoutGlobalScopes()
                    ->where('id', $id)
                    ->where('status', PostStatus::Scheduled->value)
                    ->update(['status' => PostStatus::Publishing->value]);

                if ($claimed !== 1) {
                    return;
                }

                $post = Post::query()->withoutGlobalScopes()->where('id', $id)->firstOrFail();
                $dispatcher->dispatchForPost($post);
            });
        }

        return self::SUCCESS;
    }
}
