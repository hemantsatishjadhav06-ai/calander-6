<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\StoreCreatorExportRequest;
use App\Models\Post;
use App\Services\Creator\CreatorExportService;
use App\Services\Posts\PostReviewService;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorExportController extends Controller
{
    public function context(Request $request, Post $post, PostReviewService $reviews): JsonResponse
    {
        abort_unless($request->user()->can('view', $post), 403);

        return response()->json(['workspace_id' => $post->workspace_id, 'post' => PostView::make($post->load(['targets.account', 'targets.placements', 'media'])),
            'revision' => $reviews->revision($post), 'review_status' => $reviews->status($post)]);
    }

    public function store(StoreCreatorExportRequest $request, Post $post, CreatorExportService $exports, PostReviewService $reviews): JsonResponse
    {
        $batch = $exports->attach($post, $request->validated(), $request->user()->id);
        $post = $post->fresh(['targets.account', 'targets.placements', 'media']);
        $view = PostView::make($post);
        /** @var list<array<string, mixed>> $mediaViews */
        $mediaViews = $view['media'];
        $media = collect($mediaViews)->keyBy('id');

        return response()->json(['workspace_id' => $post->workspace_id, 'post' => $view, 'media' => array_map(fn (string $id): array => $media->get($id), $batch->media_ids),
            'batch_id' => $batch->id, 'revision' => $reviews->revision($post), 'review_status' => $reviews->status($post)], 201);
    }
}
