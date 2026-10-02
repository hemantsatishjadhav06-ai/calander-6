<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\PostApprovalService;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishController extends Controller
{
    public function store(
        Request $request,
        Post $post,
        PublishDispatcher $dispatcher,
        WorkspaceSubscriptionGate $subscriptions,
        PublishPrecheck $precheck,
        PostApprovalService $approvals,
    ): JsonResponse {
        abort_unless($request->user()->can('update', $post), 403);
        abort_unless($post->status->isAwaitingPublication(), 409, 'This post cannot be published again. Retry failed targets individually.');

        $workspace = $post->workspace()->firstOrFail();

        if ($post->loadMissing('targets')->targets->isEmpty()) {
            return response()->json([
                'message' => 'Select at least one account to publish.',
            ], 422);
        }

        if (! $subscriptions->canPublish($workspace)) {
            return response()->json([
                'message' => 'Subscribe to publish this post.',
                'billing_url' => route('billing.index'),
            ], 402);
        }

        $blocked = $precheck->blockingTargets($post->loadMissing(['targets.account', 'media']));
        if ($blocked !== []) {
            return response()->json([
                'message' => "Some accounts can't be published yet.",
                'blocked' => $blocked,
            ], 422);
        }

        $approvals->assertPlan($post, null);

        $claimed = Post::query()->whereKey($post->id)
            ->where('status', $post->status->value)
            ->update(['status' => PostStatus::Publishing->value]);
        abort_unless($claimed === 1, 409, 'This post has already changed. Refresh before publishing.');
        $post->refresh();

        $dispatcher->dispatchForPost($post);

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
