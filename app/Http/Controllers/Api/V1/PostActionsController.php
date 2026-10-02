<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesWorkspacePost;
use App\Http\Controllers\Controller;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\NextSlotResolver;
use App\Services\Posts\PostApprovalService;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class PostActionsController extends Controller
{
    use ResolvesWorkspacePost;

    public function schedule(Request $request, string $id, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);
        abort_unless($model->status->isAwaitingPublication(), 409, 'Only draft, scheduled, or missed posts can be scheduled.');

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ], [
            'scheduled_at.after' => 'Choose a time in the future — a post cannot be scheduled in the past.',
        ]);

        if (($validated['scheduled_at'] ?? null) !== null) {
            abort_unless($subscriptions->canPublish($model->workspace()->firstOrFail()), 402, 'Subscribe to publish this post.');
        }

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $scheduledAt = $scheduledAt === null ? null : CarbonImmutable::parse($scheduledAt)->utc();

        if ($scheduledAt !== null) {
            $approvals->assertPlan($model, $scheduledAt);
        }

        $claimed = Post::query()->whereKey($model->id)
            ->where('status', $model->status->value)
            ->update([
                'scheduled_at' => $scheduledAt,
                'status' => $scheduledAt === null ? PostStatus::Draft->value : PostStatus::Scheduled->value,
            ]);
        abort_unless($claimed === 1, 409, 'This post has already changed. Refresh before scheduling.');

        return response()->json(['post' => PostView::make($model->fresh(['targets.account', 'media']))]);
    }

    public function queue(string $id, NextSlotResolver $resolver, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);
        abort_unless($model->status->isAwaitingPublication(), 409, 'Only draft, scheduled, or missed posts can be queued.');
        $workspace = Workspace::query()->whereKey(Context::get('workspace_id'))->firstOrFail();
        abort_unless($subscriptions->canPublish($workspace), 402, 'Subscribe to publish this post.');

        $slot = $resolver->resolve($workspace);

        if ($slot === null) {
            abort(422, 'No open posting slot available. Add posting-schedule slots first.');
        }

        $approvals->assertPlan($model, $slot);

        $claimed = Post::query()->whereKey($model->id)
            ->where('status', $model->status->value)
            ->update(['scheduled_at' => $slot, 'status' => PostStatus::Scheduled->value]);
        abort_unless($claimed === 1, 409, 'This post has already changed. Refresh before scheduling.');

        return response()->json(['post' => PostView::make($model->fresh(['targets.account', 'media']))]);
    }

    public function publish(string $id, PublishDispatcher $dispatcher, PublishPrecheck $precheck, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);
        abort_unless($model->status->isAwaitingPublication(), 409, 'This post cannot be published again. Retry failed targets individually.');
        abort_if($model->loadMissing('targets')->targets->isEmpty(), 422, 'Select at least one account to publish.');
        abort_unless($subscriptions->canPublish($model->workspace()->firstOrFail()), 402, 'Subscribe to publish this post.');

        $blocked = $precheck->blockingTargets($model->loadMissing(['targets.account', 'media']));
        if ($blocked !== []) {
            return response()->json([
                'message' => "Some accounts can't be published yet.",
                'blocked' => $blocked,
            ], 422);
        }

        $approvals->assertPlan($model, null);

        $claimed = Post::query()->whereKey($model->id)
            ->where('status', $model->status->value)
            ->update(['status' => PostStatus::Publishing->value]);
        abort_unless($claimed === 1, 409, 'This post has already changed. Refresh before publishing.');
        $model->refresh();
        $dispatcher->dispatchForPost($model);

        return response()->json([
            'status' => 'queued',
            'message' => 'Publishing started. Poll GET /posts/{id} for per-target status.',
            'post' => PostView::make($model->fresh(['targets.account', 'media'])),
        ], 202);
    }

    public function retry(string $id, string $targetId, PostStatusRollup $rollup, PostApprovalService $approvals): JsonResponse
    {
        $model = $this->findPostOrFail($id);
        $this->authorize('update', $model);
        abort_if($model->status === PostStatus::Deleted, 409, 'Deleted posts cannot be retried.');

        $postTarget = PostTarget::query()->whereKey($targetId)->where('post_id', $model->id)->first();

        if ($postTarget === null) {
            abort(404, 'No such target on that post.');
        }

        if (! $postTarget->status->isRetryable()) {
            abort(422, 'Only failed or skipped targets can be retried.');
        }

        $approvals->assertApproved($model);

        $postTarget->forceFill([
            'status' => PostTargetStatus::Pending->value,
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        PublishPostTarget::dispatch($postTarget);
        $rollup->recompute($model);

        return response()->json([
            'status' => 'queued',
            'post' => PostView::make($model->fresh(['targets.account', 'media'])),
        ], 202);
    }
}
