<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Override;

#[Description('Remove an image from the workspace media library by id.')]
class RemovePostMediaTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate(['media_id' => ['required', 'string']]);

        $media = PostMedia::query()->whereKey($validated['media_id'])->first();
        if ($media === null) {
            return Response::error('No media with that id exists in this workspace.');
        }

        $removed = DB::transaction(function () use ($media): bool {
            $media = PostMedia::query()->whereKey($media->id)->lockForUpdate()->firstOrFail();
            if ($media->post_id !== null) {
                $post = Post::withoutGlobalScopes()->whereKey($media->post_id)->lockForUpdate()->first();
                if ($post !== null && (! $post->status->isEditable() || $post->targets()->where('status', 'publishing')->exists())) {
                    return false;
                }
            }

            $media->delete();

            return true;
        });
        if (! $removed) {
            return Response::error('This post can no longer be edited.');
        }

        return Response::text(json_encode(['deleted' => true, 'id' => $validated['media_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return ['media_id' => $schema->string()->description('Id of the media to remove.')->required()];
    }
}
