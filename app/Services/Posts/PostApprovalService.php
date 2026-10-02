<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PostApprovalService
{
    public function required(Post $post): bool
    {
        return (bool) $post->workspace()->value('requires_post_approval');
    }

    /**
     * Hash only what the owner reviews. Publication progress, metric refreshes,
     * and updated_at are deliberately excluded so retries retain their approval.
     */
    public function revision(Post $post): string
    {
        $current = Post::withoutGlobalScopes()->findOrFail($post->id);

        return $this->snapshotRevision($current);
    }

    private function snapshotRevision(Post $current): string
    {
        $current->loadMissing([
            'workspace',
            'targets.account' => fn (Relation $relation): Builder => $relation->getQuery()->withoutGlobalScopes()->where('workspace_id', $current->workspace_id),
            'targets.account.secret',
            'targets.placements',
            'media' => fn (Relation $relation): Builder => $relation->getQuery()->withoutGlobalScopes()->where('workspace_id', $current->workspace_id),
        ]);
        $content = [
            'workspace_id' => $current->workspace_id,
            'base_text' => $current->base_text,
            'segments' => $current->segments,
            'mentions' => $current->mentions,
            'auto_repost' => $current->auto_repost,
            'planned_schedule_at' => $current->planned_schedule_at?->utc()->toIso8601String(),
            'targets' => $current->targets->sortBy('connected_account_id')->map(fn (PostTarget $target): array => [
                'account_id' => $target->connected_account_id,
                'remote_account_id' => $target->account?->remote_account_id,
                'account_platform' => $target->account?->platform->value,
                'transport' => self::destinationTransport($target->account?->secret?->session),
                'platform' => $target->platform->value,
                'sections' => $target->sections,
                'segment_breaks' => $target->segment_breaks,
                'section_sources' => $target->section_sources,
                'content_override' => $target->content_override,
                'auto_split' => $target->auto_split,
                'format' => $target->format->value,
                'placements' => $target->placements->sortBy('post_media_id')->map(fn (PostMediaPlacement $placement): array => [
                    'media_id' => $placement->post_media_id,
                    'segment_ref' => $placement->segment_ref,
                    'position' => $placement->position,
                ])->values()->all(),
            ])->values()->all(),
            'media' => $current->media->sortBy('id')->map(fn (PostMedia $media): array => [
                'id' => $media->id,
                'disk' => $media->disk,
                'path' => $media->path,
                'mime' => $media->mime,
                'kind' => $media->kind,
                'size_bytes' => $media->size_bytes,
                'width' => $media->width,
                'height' => $media->height,
                'duration_seconds' => $media->duration_seconds,
                'alt_text' => $media->alt_text,
                'position' => $media->position,
                'edit_settings' => $media->edit_settings,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }

    public function isApproved(Post $post): bool
    {
        if (! $this->required($post)) {
            return true;
        }

        $current = Post::withoutGlobalScopes()->findOrFail($post->id);
        $ownerId = $current->workspace()->value('owner_id');

        return $current->approved_revision !== null && $current->approved_at !== null
            && $current->approved_by === $ownerId
            && $this->hasLiveOwner($current, (string) $ownerId)
            && hash_equals($current->approved_revision, $this->revision($current));
    }

    public function matchesApprovedSnapshot(Post $post): bool
    {
        if (! $this->required($post)) {
            return true;
        }
        if (! $this->isApproved($post)) {
            return false;
        }

        $current = Post::withoutGlobalScopes()->findOrFail($post->id);

        return $current->approved_revision !== null && hash_equals($current->approved_revision, $this->snapshotRevision($post));
    }

    public function assertApproved(Post $post): void
    {
        if (! $this->isApproved($post)) {
            throw ValidationException::withMessages(['approval' => 'The workspace owner must approve the current content in the dashboard before publishing.']);
        }
    }

    public function assertPlan(Post $post, ?CarbonInterface $scheduledAt): void
    {
        if (! $this->required($post)) {
            return;
        }

        $this->assertApproved($post);
        $planned = Post::withoutGlobalScopes()->findOrFail($post->id)->planned_schedule_at;
        if ($planned?->utc()->toIso8601String() !== $scheduledAt?->copy()->utc()->toIso8601String()) {
            throw ValidationException::withMessages(['approval' => 'Save the intended publishing time and request fresh dashboard approval before changing the publishing plan.']);
        }
    }

    /** @return array<string, mixed> */
    public function toView(Post $post, ?User $user = null): array
    {
        $current = $post;
        $revision = $this->snapshotRevision($current);
        $required = (bool) $current->workspace->requires_post_approval;
        $approved = $current->approved_revision === $revision && $current->approved_at !== null && $current->approved_by === $current->workspace->owner_id
            && $this->hasLiveOwner($current, (string) $current->workspace->owner_id);
        $status = match (true) {
            $approved => 'approved',
            $current->rejected_revision === $revision => 'rejected',
            $current->review_requested_revision === $revision => 'awaiting_approval',
            default => 'draft',
        };

        return [
            'required' => $required,
            'status' => $status,
            'revision' => $revision,
            'reviewed_revision' => $current->approved_revision,
            'planned_schedule_at' => $current->planned_schedule_at?->toIso8601String(),
            'requested_at' => $current->review_requested_at?->toIso8601String(),
            'approved_at' => $current->approved_at?->toIso8601String(),
            'approved_by' => $current->approved_by,
            'rejected_at' => $current->rejected_at?->toIso8601String(),
            'rejected_by' => $current->rejected_by,
            'rejection_reason' => $current->rejection_reason,
            'can_approve' => $required && $user !== null && $current->workspace->owner_id === $user->id && $this->hasLiveOwner($current, $user->id)
                && $current->status !== PostStatus::Publishing && $current->status !== PostStatus::Deleted,
        ];
    }

    /** Changes are serialized against the same post lock used by publication claims. */
    public function review(Post $post, User $user, string $action, string $revision, ?string $reason = null, ?CarbonInterface $plannedAt = null): Post
    {
        return DB::transaction(function () use ($post, $user, $action, $revision, $reason, $plannedAt): Post {
            $current = Post::withoutGlobalScopes()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->required($current), 422, 'Approval is not required in this workspace.');
            abort_unless($user->can('update', $current), 403);
            $this->assertReviewable($current);
            abort_unless(hash_equals($this->revision($current), $revision), 409, 'This draft changed. Review the latest version before continuing.');

            if (in_array($action, ['approve', 'reject', 'revoke'], true)) {
                abort_unless($current->workspace()->value('owner_id') === $user->id && $this->hasLiveOwner($current, $user->id), 403, 'Only the workspace owner can approve or reject drafts.');
            }

            if ($action === 'approve' || $action === 'reject') {
                abort_unless($current->review_requested_revision === $revision, 409, 'Request review of the current draft before approving or rejecting it.');
            }

            $clear = [
                'approved_revision' => null, 'approved_by' => null, 'approved_at' => null,
                'rejected_revision' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ];
            $attributes = match ($action) {
                'request' => [...$clear, 'review_requested_revision' => $revision, 'review_requested_at' => now()],
                'approve' => [...$clear, 'approved_revision' => $revision, 'approved_by' => $user->id, 'approved_at' => now()],
                'reject' => [...$clear, 'rejected_revision' => $revision, 'rejected_by' => $user->id, 'rejected_at' => now(), 'rejection_reason' => $reason],
                'revoke' => [...$clear, 'review_requested_revision' => null, 'review_requested_at' => null],
                'plan' => $current->planned_schedule_at?->toIso8601String() === $plannedAt?->copy()->utc()->toIso8601String()
                    ? []
                    : [...$clear, 'review_requested_revision' => null, 'review_requested_at' => null, 'planned_schedule_at' => $plannedAt?->copy()->utc()],
                default => throw new InvalidArgumentException('Unknown review action.'),
            };
            if ($action === 'plan') {
                abort_unless($current->status->isAwaitingPublication(), 409, 'Only drafts, scheduled, or missed posts can change their publishing plan.');
            }
            if ($current->status === PostStatus::Scheduled && in_array($action, ['request', 'reject', 'revoke'], true)) {
                $attributes = [...$attributes, 'status' => PostStatus::Draft, 'scheduled_at' => null];
            }
            if ($action === 'plan' && $attributes !== []) {
                $attributes = [...$attributes, 'status' => PostStatus::Draft, 'scheduled_at' => null];
            }
            $current->forceFill($attributes)->save();

            return $current->fresh(['targets.account', 'targets.placements', 'media']);
        });
    }

    /** @param array<string, mixed>|null $session
     * @return array<string, string|null>
     */
    public static function destinationTransport(?array $session): array
    {
        $transport = [];
        foreach (['pds', 'auth_server', 'issuer', 'token_endpoint'] as $key) {
            $value = $session[$key] ?? null;
            $transport[$key] = is_string($value) ? rtrim(trim($value), '/') : null;
        }

        return $transport;
    }

    public function hasLiveOwner(Post $post, string $ownerId): bool
    {
        return $post->workspace()->value('owner_id') === $ownerId && WorkspaceMembership::query()->where('workspace_id', $post->workspace_id)
            ->where('user_id', $ownerId)->where('role', WorkspaceRole::Owner->value)->exists();
    }

    public function invalidate(Post $post): void
    {
        Post::withoutGlobalScopes()->whereKey($post->id)->update(self::clearedReview());
    }

    /** @return array<string, null> */
    public static function clearedReview(): array
    {
        return [
            'review_requested_revision' => null, 'review_requested_at' => null,
            'approved_revision' => null, 'approved_by' => null, 'approved_at' => null,
            'rejected_revision' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
        ];
    }

    public function assertReviewable(Post $post): void
    {
        abort_if($post->status === PostStatus::Publishing || $post->status === PostStatus::Deleted
            || $post->targets()->where('status', PostTargetStatus::Publishing->value)->exists(), 409,
            'This post is publishing or deleted and cannot be changed.');
    }
}
