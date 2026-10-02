<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\NextSlotResolver;
use App\Services\Posts\PostApprovalService;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Override;

#[Description('Schedule a post into the next open slot of the workspace posting schedule.')]
class QueuePostTool extends WorkspaceTool
{
    public function handle(Request $request, NextSlotResolver $resolver, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate(['post_id' => ['required', 'string']]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        if (! $post->status->isAwaitingPublication()) {
            return Response::error('Only draft, scheduled, or missed posts can be queued.');
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->firstOrFail();
        if (! $subscriptions->canPublish($workspace)) {
            return Response::error('This workspace requires an active subscription before publishing.');
        }
        $slot = $resolver->resolve($workspace);

        if ($slot === null) {
            return Response::error('No open posting slot available. Add posting-schedule slots first (see get_posting_schedule).');
        }

        try {
            $approvals->assertPlan($post, $slot);
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        $claimed = Post::query()->whereKey($post->id)
            ->where('status', $post->status->value)
            ->update(['scheduled_at' => $slot, 'status' => PostStatus::Scheduled->value]);
        if ($claimed !== 1) {
            return Response::error('This post has already changed. Refresh before scheduling.');
        }

        return Response::text(json_encode(PostView::make($post->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->string()->description('Id of the post to queue.')->required()];
    }
}
