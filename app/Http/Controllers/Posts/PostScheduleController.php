<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\SchedulePostRequest;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\PostApprovalService;
use App\Support\PostView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PostScheduleController extends Controller
{
    public function update(SchedulePostRequest $request, Post $post, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): JsonResponse|RedirectResponse
    {
        abort_unless($post->status->isAwaitingPublication(), 409, 'Only draft, scheduled, or missed posts can be scheduled.');

        $workspace = $post->workspace()->firstOrFail();

        if ($request->validated('scheduled_at') !== null && ! $subscriptions->canPublish($workspace)) {
            if ($request->headers->has('X-Inertia')) {
                return redirect()->route('billing.index');
            }

            return response()->json([
                'message' => 'Subscribe to publish this post.',
                'billing_url' => route('billing.index'),
            ], 402);
        }

        $scheduledAt = $request->validated('scheduled_at');
        $scheduledAt = $scheduledAt === null ? null : CarbonImmutable::parse($scheduledAt)->utc();

        if ($scheduledAt !== null) {
            $approvals->assertPlan($post, $scheduledAt);
        }

        $claimed = Post::query()->whereKey($post->id)
            ->where('status', $post->status->value)
            ->update([
                'scheduled_at' => $scheduledAt,
                'status' => $scheduledAt === null ? PostStatus::Draft->value : PostStatus::Scheduled->value,
            ]);
        abort_unless($claimed === 1, 409, 'This post has already changed. Refresh before scheduling.');

        if ($request->headers->has('X-Inertia')) {
            return back();
        }

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
