<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Posts\PostDeletionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Override;

#[Description('Delete a post. A draft is removed permanently; a published post is also deleted from the connected accounts where possible. Irreversible. Requires confirm=true.')]
class DeletePostTool extends WorkspaceTool
{
    public function handle(Request $request, PostDeletionService $deletion): Response
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

        if ($denied = $this->authorize($request, 'delete', $post)) {
            return $denied;
        }

        $hadBeenPublished = in_array($post->status, [PostStatus::Publishing, PostStatus::Published, PostStatus::Partial, PostStatus::Failed, PostStatus::Deleted], true);
        $consequence = $hadBeenPublished
            ? 'This will delete the post from its connected accounts where possible.'
            : 'This will permanently delete the draft.';

        if ($unconfirmed = $this->requireConfirmation($request, $consequence)) {
            return $unconfirmed;
        }

        $hadBeenPublished = $deletion->delete($post);

        if (! $hadBeenPublished) {
            return Response::text(json_encode(['deleted' => true, 'remote' => false], JSON_THROW_ON_ERROR));
        }

        return Response::text(json_encode(['deleted' => true, 'remote' => true, 'message' => 'Remote deletion queued for published targets.'], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to delete.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to delete.'),
        ];
    }
}
