<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Services\Posts\PostApprovalService;
use App\Services\Posts\ShareService;
use App\Support\PublicPostView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Inertia\Inertia;
use Inertia\Response;

class PublicShareController extends Controller
{
    public function show(string $token, ShareService $shares): Response
    {
        $share = $shares->resolveActive($token);

        // Token proves authorization; bypass the workspace global scope for the
        // cross-workspace read, including the shared media and account details.
        $post = $share
            ?->post()
            ->withoutGlobalScope('workspace')
            ->where('status', '!=', PostStatus::Deleted->value)
            ->whereNull('deleted_at')
            ->with([
                'targets.account' => fn (Relation $relation): Builder => $relation->getQuery()->withoutGlobalScope('workspace'),
                'media' => fn (Relation $relation): Builder => $relation->getQuery()->withoutGlobalScope('workspace'),
            ])
            ->first();

        if ($post !== null && ! app(PostApprovalService::class)->matchesApprovedSnapshot($post)) {
            $post = null;
        }

        return Inertia::render('share/show', [
            'post' => $post !== null ? PublicPostView::make($post) : null,
        ]);
    }
}
