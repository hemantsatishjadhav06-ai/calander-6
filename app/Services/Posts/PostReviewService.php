<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\PostWorkflowEvent;
use App\Models\User;
use App\Services\Creator\CreatorExportFreshness;
use App\Services\Reviews\StagedPostReviewService;
use App\Services\Scheduling\EditorialPublishingGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostReviewService
{
    /** A content-bound approval covers every destination, override and media placement. */
    public function revision(Post $post): string
    {
        $post->load(['targets.placements', 'media']);
        $content = [
            'segments' => $post->segments,
            'mentions' => $post->mentions,
            'auto_repost' => $post->auto_repost,
            'first_comment' => [$post->first_comment_enabled, $post->first_comment],
            'targets' => $post->targets->sortBy('id')->map(fn (PostTarget $target): array => [
                'account' => $target->connected_account_id,
                'sections' => $target->sections,
                'segment_breaks' => $target->segment_breaks,
                'section_sources' => $target->section_sources,
                'format' => $target->format->value,
                'first_comment' => [$target->first_comment_enabled, $target->first_comment],
                'override' => $target->content_override,
                'placements' => $target->placements->sortBy('id')->map(fn (PostMediaPlacement $placement): array => [
                    $placement->post_media_id, $placement->segment_ref, $placement->position,
                ])->values()->all(),
            ])->values()->all(),
            'media' => $post->media->sortBy('id')->map(fn (PostMedia $media): array => [
                $media->id, $media->path, $media->disk, $media->alt_text,
                $media->position, $media->edit_settings, $media->updated_at?->toISOString(),
            ])->values()->all(),
        ];

        $creatorExports = app(CreatorExportFreshness::class)->snapshot($post);
        if ($creatorExports !== []) {
            $content['creator_exports'] = $creatorExports;
        }

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }

    public function status(Post $post): string
    {
        $staged = app(StagedPostReviewService::class)->status($post);
        if ($staged !== null) {
            return $staged;
        }

        if (! $post->getAttribute('review_required')) {
            return 'not_required';
        }

        if ($post->getAttribute('review_revision') !== $this->revision($post)) {
            return 'stale';
        }

        return (string) $post->getAttribute('review_status');
    }

    public function canPublish(Post $post): bool
    {
        return app(EditorialPublishingGuard::class)->allows($post)
            && ! app(CreatorExportFreshness::class)->hasStaleExports($post)
            && in_array($this->status($post), ['not_required', 'approved'], true);
    }

    public function act(Post $post, User $actor, string $action, string $revision, ?string $note, string $source): Post
    {
        $staged = app(StagedPostReviewService::class);
        if ($staged->mode($post) !== 'off') {
            return $staged->act($post, $actor, $action, $revision, $note, $source);
        }

        abort_unless($actor->hasAllPermissions(['workspace.read'], $post->workspace_id), 403);
        if ($action !== 'submit') {
            abort_unless($actor->hasAllPermissions(['workspace.settings.manage'], $post->workspace_id), 403);
        }

        return DB::transaction(function () use ($post, $actor, $action, $revision, $note, $source): Post {
            $locked = Post::withoutGlobalScopes()->lockForUpdate()->findOrFail($post->id);
            abort_unless(in_array($locked->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed, PostStatus::Missed], true), 422, 'Review is available for drafts, scheduled posts and failed or missed posts.');
            if (in_array($action, ['submit', 'approve'], true) && app(CreatorExportFreshness::class)->hasStaleExports($locked)) {
                throw ValidationException::withMessages(['review' => 'Export the current design revision or remove its outdated media before requesting approval.']);
            }
            $current = $this->revision($locked);
            abort_unless(hash_equals($current, $revision), 409, 'The content changed. Reload and review the current revision.');
            if ($locked->getAttribute('review_revision') === $current && $locked->getAttribute('review_status') === match ($action) {
                'submit' => 'pending', 'approve' => 'approved', 'request_changes' => 'changes_requested', default => ''
            } && $locked->getAttribute('review_note') === $note) {
                return $locked;
            }
            if ($action === 'approve' && $this->status($locked) !== 'pending') {
                throw ValidationException::withMessages(['review' => 'Submit this revision for review before approving it.']);
            }
            $status = match ($action) {
                'submit' => 'pending',
                'approve' => 'approved',
                'request_changes' => 'changes_requested',
                default => throw ValidationException::withMessages(['action' => 'Unknown review action.']),
            };
            $locked->forceFill([
                'review_required' => true,
                'review_status' => $status,
                'review_revision' => $current,
                'review_note' => $note,
            ])->save();
            $this->record($locked, $action, $source, $actor->id, $note);

            return $locked;
        });
    }

    public function record(Post $post, string $action, string $source, ?string $actorId = null, ?string $note = null): void
    {
        PostWorkflowEvent::create([
            'workspace_id' => $post->workspace_id,
            'post_id' => $post->id,
            'actor_id' => $actorId,
            'source' => $source,
            'action' => $action,
            'revision' => $this->revision($post),
            'note' => $note,
        ]);
    }
}
