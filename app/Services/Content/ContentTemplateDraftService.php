<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Dto\Post\DraftData;
use App\Enums\PostStatus;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use App\Models\CreatorAsset;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceBrandProfile;
use App\Services\Creator\CreatorProjectService;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostReviewService;
use App\Support\FileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ContentTemplateDraftService
{
    public function create(User $actor, ContentTemplate $template, int $revision): Post
    {
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $template, $revision, &$written): Post {
                $locked = ContentTemplate::query()->where('workspace_id', $actor->current_workspace_id)->lockForUpdate()->findOrFail($template->id);
                abort_unless($locked->revision === $revision, 409, 'The template changed. Reload before creating a draft.');
                abort_if($locked->archived_at !== null, 422, 'Restore this template before creating a draft.');

                return $this->build($actor, $locked, null, $written);
            });
        } catch (Throwable $exception) {
            $this->cleanup($written);
            throw $exception;
        }
    }

    public function convert(User $actor, ContentIdea $idea, int $revision): Post
    {
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $idea, $revision, &$written): Post {
                Workspace::query()->whereKey($actor->current_workspace_id)->lockForUpdate()->firstOrFail();
                $locked = ContentIdea::query()->where('workspace_id', $actor->current_workspace_id)->lockForUpdate()->findOrFail($idea->id);
                if ($locked->draft_post_id !== null) {
                    $existing = Post::query()->where('workspace_id', $locked->workspace_id)->findOrFail($locked->draft_post_id);
                    abort_if($existing->status === PostStatus::Deleted, 409, 'This idea’s draft was deleted. Restore the draft or create a new idea.');

                    return $existing;
                }
                abort_unless($locked->revision === $revision, 409, 'The idea changed. Reload before converting.');
                abort_if(in_array($locked->status, ['archived', 'drafted'], true), 422, 'Only an active, unconverted idea can become a draft.');
                $template = $locked->template_id ? ContentTemplate::query()->where('workspace_id', $locked->workspace_id)->lockForUpdate()->findOrFail($locked->template_id) : null;
                abort_if($template?->archived_at !== null, 422, 'The selected template was archived. Choose an active template.');

                return $this->build($actor, $template, $locked, $written);
            });
        } catch (Throwable $exception) {
            $this->cleanup($written);
            throw $exception;
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $written
     */
    private function build(User $actor, ?ContentTemplate $template, ?ContentIdea $idea, array &$written): Post
    {
        $workspaceId = (string) $actor->current_workspace_id;
        $brand = WorkspaceBrandProfile::query()->where('workspace_id', $workspaceId)->first();
        $caption = trim($idea?->caption ?: ($template->caption ?? ''));
        $tags = array_values(array_unique([...($brand->default_hashtags ?? []), ...($template->hashtags ?? [])]));
        if ($tags !== [] && $caption !== '') {
            $caption .= "\n\n".implode(' ', $tags);
        }
        $destination = $this->destination($workspaceId, $template->destination ?? ['kind' => 'none']);
        $drafts = app(DraftService::class);
        $accountIds = $drafts->resolveDestinationAccountIds($workspaceId, $destination);
        $unavailableAccountIds = array_diff($destination['ids'] ?? [], $accountIds);
        abort_if($unavailableAccountIds !== [], 422, 'A template destination is disabled or unavailable. Update the template destinations.');
        $assetIds = $template->media_asset_ids ?? [];
        $assets = CreatorAsset::query()->where('workspace_id', $workspaceId)->whereIn('id', $assetIds)->get()->keyBy('id');
        abort_unless($assets->count() === count($assetIds), 422, 'A template media reference is no longer available in this workspace.');
        $suggestedComment = $template->first_comment ?? ($brand?->first_comment_enabled ? $brand->first_comment : null);
        $post = $drafts->createDraft($workspaceId, $actor, $destination, [$caption], data: DraftData::fromArray([
            'segments' => [$caption], 'first_comment' => $suggestedComment, 'first_comment_enabled' => false,
        ]));
        $placements = [];
        foreach ($assetIds as $position => $assetId) {
            $asset = $assets->get($assetId);
            abort_unless($asset instanceof CreatorAsset, 422);
            $extension = match ($asset->mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => throw new RuntimeException('Unsupported template media type.'),
            };
            $path = 'media/'.$workspaceId.'/'.Str::uuid().'.'.$extension;
            $written[] = [$asset->disk, $path];
            if (! FileStorage::disk($asset->disk)->copy($asset->path, $path)) {
                throw new RuntimeException('The template image could not be copied. Try again.');
            }
            $media = PostMedia::create(['workspace_id' => $workspaceId, 'post_id' => $post->id, 'disk' => $asset->disk, 'path' => $path,
                'mime' => $asset->mime, 'kind' => 'image', 'width' => $asset->width, 'height' => $asset->height, 'size_bytes' => $asset->size_bytes, 'position' => $position]);
            $placements[] = ['media_id' => $media->id, 'segment_ref' => '__head__', 'position' => $position];
        }
        $drafts->syncTargets($post, $accountIds, [$caption], [], [], [], [], DraftData::fromArray(['segments' => [$caption], 'placements' => $placements]));
        $post->forceFill(['review_required' => true, 'review_status' => 'draft', 'review_revision' => null])->save();
        $project = $template?->document !== null ? app(CreatorProjectService::class)->create($actor, ['name' => $idea->title ?? $template->name, 'document' => $template->document]) : null;
        if ($idea !== null) {
            $position = (int) ContentIdea::query()->where('workspace_id', $workspaceId)->where('status', 'drafted')->max('position') + 1;
            $idea->forceFill(['draft_post_id' => $post->id, 'creator_project_id' => $project?->id, 'status' => 'drafted', 'position' => $position, 'revision' => $idea->revision + 1])->save();
        }
        app(PostReviewService::class)->record($post, $idea ? 'idea_converted' : 'template_draft_created', 'content', $actor->id);
        if ($project !== null) {
            $post->setAttribute('content_creator_project_id', $project->id);
        }

        return $post;
    }

    /**
     * @param  array{kind: string, ids?: list<string>}  $destination
     * @return array{kind: string, ids?: list<string>}
     */
    private function destination(string $workspaceId, array $destination): array
    {
        if ($destination['kind'] === 'default') {
            $set = AccountSet::query()->where('workspace_id', $workspaceId)->where('is_default', true)->first();
            $defaultId = Workspace::query()->whereKey($workspaceId)->value('default_connected_account_id');
            $ids = $set ? array_values($set->accounts()->where('connected_accounts.workspace_id', $workspaceId)->pluck('connected_accounts.id')->map(static fn (mixed $id): string => (string) $id)->all()) : ($defaultId ? [(string) $defaultId] : []);
            abort_if($ids === [], 422, 'Choose a default account or account set in this workspace, or select template destinations.');
            $destination = ['kind' => 'accounts', 'ids' => $ids];
        }
        abort_unless(in_array($destination['kind'], ['none', 'accounts'], true), 422, 'Choose valid template destinations.');
        if ($destination['kind'] === 'none') {
            return ['kind' => 'none'];
        }
        $ids = $destination['ids'] ?? [];
        abort_if($ids === [] || count($ids) !== count(array_unique($ids)), 422, 'Choose at least one distinct destination.');
        abort_unless(ConnectedAccount::query()->where('workspace_id', $workspaceId)->whereIn('id', $ids)->enabled()->count() === count($ids), 422, 'A template destination is no longer available in this workspace.');

        return ['kind' => 'accounts', 'ids' => $ids];
    }

    /** @param list<array{0: string, 1: string}> $written */
    private function cleanup(array $written): void
    {
        foreach ($written as [$disk, $path]) {
            FileStorage::disk($disk)->delete($path);
        }
    }
}
