<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\ReviewPostRequest;
use App\Models\Post;
use App\Services\Posts\PostApprovalService;
use App\Support\PostView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PostReviewController extends Controller
{
    public function requestReview(ReviewPostRequest $request, Post $post, PostApprovalService $approvals): JsonResponse
    {
        return $this->respond($request, $post, 'request', $approvals);
    }

    public function plan(ReviewPostRequest $request, Post $post, PostApprovalService $approvals): JsonResponse
    {
        return $this->respond($request, $post, 'plan', $approvals);
    }

    public function approve(ReviewPostRequest $request, Post $post, PostApprovalService $approvals): JsonResponse
    {
        return $this->respond($request, $post, 'approve', $approvals);
    }

    public function reject(ReviewPostRequest $request, Post $post, PostApprovalService $approvals): JsonResponse
    {
        return $this->respond($request, $post, 'reject', $approvals);
    }

    public function revoke(ReviewPostRequest $request, Post $post, PostApprovalService $approvals): JsonResponse
    {
        return $this->respond($request, $post, 'revoke', $approvals);
    }

    private function respond(ReviewPostRequest $request, Post $post, string $action, PostApprovalService $approvals): JsonResponse
    {
        try {
            $updated = $approvals->review(
                $post, $request->user(), $action, $request->validated('revision'), $request->validated('reason'),
                $request->validated('scheduled_at') === null ? null : CarbonImmutable::parse($request->validated('scheduled_at'))->utc(),
            );
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return response()->json(['message' => $exception->getMessage(), 'post' => PostView::make($post->fresh(['targets.account', 'targets.placements', 'media']))], 409);
        }

        return response()->json(['post' => PostView::make($updated)]);
    }
}
