<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostTargetStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendFirstComment;
use App\Models\FirstCommentDelivery;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FirstCommentRetryController extends Controller
{
    public function store(Request $request, Post $post, PostTarget $target): JsonResponse
    {
        $request->user()->can('update', $post) ?: abort(403);
        abort_unless($target->post_id === $post->id, 404);
        $delivery = DB::transaction(function () use ($post, $target): FirstCommentDelivery {
            Post::withoutGlobalScopes()->lockForUpdate()->findOrFail($post->id);
            $target = PostTarget::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless($target->status === PostTargetStatus::Published, 422, 'Publish this destination before retrying its comment.');
            $delivery = $target->firstCommentDelivery()->lockForUpdate()->firstOrFail();
            abort_unless(in_array($delivery->status, ['retryable', 'blocked'], true) && $delivery->attempts < 3, 422, 'Only a safely rejected first comment can be retried. An uncertain delivery must be checked on the platform.');
            $delivery->forceFill(['status' => 'pending', 'next_attempt_at' => null, 'error_message' => null])->save();

            return $delivery;
        });
        SendFirstComment::dispatch($delivery->id)->afterCommit();

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'targets.placements', 'targets.firstCommentDelivery', 'media']))]);
    }
}
