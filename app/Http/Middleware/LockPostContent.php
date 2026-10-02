<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Post;
use App\Services\Posts\PostApprovalService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LockPostContent
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $post = $request->route('post');
            if ($post instanceof Post) {
                $current = Post::withoutGlobalScopes()->whereKey($post->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->status->isEditable(), 422, 'This post can no longer be edited.');
                app(PostApprovalService::class)->assertReviewable($current);
                $request->route()->setParameter('post', $current);
            }

            return $next($request);
        });
    }
}
