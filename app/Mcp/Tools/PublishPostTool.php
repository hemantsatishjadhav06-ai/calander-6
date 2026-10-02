<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\PostApprovalService;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Override;

#[Description('Publish a post to its connected accounts immediately. Irreversible and outward-facing. Requires confirm=true. Publishing is asynchronous — poll get_post for per-target results.')]
class PublishPostTool extends WorkspaceTool
{
    public function handle(Request $request, PublishDispatcher $dispatcher, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        if (! $post->status->isAwaitingPublication()) {
            return Response::error('This post cannot be published again. Retry failed targets individually.');
        }

        if ($post->loadMissing('targets')->targets->isEmpty()) {
            return Response::error('Select at least one account to publish.');
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will publicly publish the post to its connected accounts now.')) {
            return $unconfirmed;
        }

        if (! $subscriptions->canPublish($post->workspace()->firstOrFail())) {
            return Response::error('This workspace requires an active subscription before publishing.');
        }

        try {
            $approvals->assertPlan($post, null);
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        $claimed = Post::query()->whereKey($post->id)
            ->where('status', $post->status->value)
            ->update(['status' => PostStatus::Publishing->value]);
        if ($claimed !== 1) {
            return Response::error('This post has already changed. Refresh before publishing.');
        }
        $post->refresh();
        $dispatcher->dispatchForPost($post);

        return Response::text(json_encode([
            'status' => 'queued',
            'message' => 'Publishing started. Poll get_post for per-target status.',
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to publish.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to actually publish.'),
        ];
    }
}
