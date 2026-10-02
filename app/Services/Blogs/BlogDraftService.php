<?php

declare(strict_types=1);

namespace App\Services\Blogs;

use App\Enums\WorkspaceRole;
use App\Models\BlogDraft;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BlogDraftService
{
    public const array CONTENT_FIELDS = [
        'title', 'slug', 'body', 'excerpt', 'featured_image_url', 'featured_image_alt',
        'seo_title', 'seo_description', 'canonical_url',
    ];

    public function workspace(User $user, bool $ownerOnly = false, bool $lock = false): Workspace
    {
        $query = Workspace::query()->whereKey($user->current_workspace_id)
            ->withExists(['members as owner_has_active_membership' => fn (Builder $query) => $query
                ->whereColumn('workspace_memberships.user_id', 'workspaces.owner_id')
                ->where('role', WorkspaceRole::Owner->value)]);
        $workspace = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first();
        abort_unless($membership !== null, 403);
        abort_if($ownerOnly && ($workspace->owner_id !== $user->id || $membership->role !== WorkspaceRole::Owner), 403,
            'Only the workspace owner can review blog drafts.');

        return $workspace;
    }

    /** @return array{website_url: string|null, netlify_site_id: string|null, repository_url: string|null} */
    public function destination(string $workspaceId): array
    {
        $profile = BrandProfile::withoutGlobalScope('workspace')->where('workspace_id', $workspaceId)->first();

        return [
            'website_url' => $profile?->website_url,
            'netlify_site_id' => $profile?->netlify_site_id,
            'repository_url' => $profile?->repository_url,
        ];
    }

    /** @param array<string, string|null>|null $destination */
    public function revision(BlogDraft $draft, ?array $destination = null): string
    {
        return hash('sha256', json_encode([
            'workspace_id' => $draft->workspace_id,
            'content_revision' => $draft->content_revision,
            'content' => $draft->only(self::CONTENT_FIELDS),
            'destination' => $destination ?? $this->destination($draft->workspace_id),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): BlogDraft
    {
        return DB::transaction(function () use ($user, $data): BlogDraft {
            $workspace = $this->workspace($user, lock: true);
            $this->assertUniqueSlug($workspace, (string) $data['slug']);

            return BlogDraft::query()->create([
                ...Arr::only($data, self::CONTENT_FIELDS),
                'workspace_id' => $workspace->id,
                'author_id' => $user->id,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, BlogDraft $draft, array $data, string $revision): BlogDraft
    {
        return DB::transaction(function () use ($user, $draft, $data, $revision): BlogDraft {
            $workspace = $this->workspace($user, lock: true);
            $current = $this->locked($draft, $workspace);
            $this->assertNotPublishing($current);
            $this->assertRevision($current, $revision);
            $current->fill(Arr::only($data, self::CONTENT_FIELDS));
            $this->assertUniqueSlug($workspace, $current->slug, $current->id);
            if ($current->isDirty(self::CONTENT_FIELDS)) {
                $current->forceFill([
                    ...$this->clearReview(),
                    'content_revision' => $current->content_revision + 1,
                ])->save();
            }

            return $current->refresh();
        });
    }

    public function review(User $user, BlogDraft $draft, string $action, string $revision, ?string $reason = null): BlogDraft
    {
        return DB::transaction(function () use ($user, $draft, $action, $revision, $reason): BlogDraft {
            $workspace = $this->workspace($user, ownerOnly: true, lock: true);
            $current = $this->locked($draft, $workspace);
            $this->assertNotPublishing($current);
            $this->assertRevision($current, $revision);
            if ($action !== 'request' && $current->review_requested_revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Request review of this version before approving or rejecting it.']);
            }
            $attributes = match ($action) {
                'request' => ['review_requested_revision' => $revision, 'review_requested_at' => now()],
                'approve' => [
                    'review_requested_revision' => $revision, 'review_requested_at' => $current->review_requested_at,
                    'approved_revision' => $revision, 'approved_by' => $user->id, 'approved_at' => now(),
                ],
                'reject' => [
                    'review_requested_revision' => $revision, 'review_requested_at' => $current->review_requested_at,
                    'rejected_revision' => $revision, 'rejected_by' => $user->id, 'rejected_at' => now(), 'rejection_reason' => $reason,
                ],
                default => throw new InvalidArgumentException('Unknown blog review action.'),
            };
            $current->forceFill([...$this->clearReview(), ...$attributes])->save();

            return $current->refresh();
        });
    }

    /**
     * @param  array<string, string|null>  $destination
     * @return array<string, mixed>
     */
    public function toView(BlogDraft $draft, User $user, Workspace $workspace, array $destination, bool $includeContent = true): array
    {
        $revision = $this->revision($draft, $destination);
        $status = match (true) {
            $draft->approved_revision === $revision && $draft->approved_at !== null && $draft->approved_by === $workspace->owner_id
                && $this->ownerHasMembership($workspace) => 'approved',
            $draft->rejected_revision === $revision => 'rejected',
            $draft->review_requested_revision === $revision => 'awaiting_approval',
            default => 'draft',
        };

        return [
            ...$draft->only($includeContent ? self::CONTENT_FIELDS : ['title', 'slug', 'excerpt']),
            'id' => $draft->id,
            'content_revision' => $draft->content_revision,
            'revision' => $revision,
            'status' => $status,
            'requested_at' => $draft->review_requested_at?->toIso8601String(),
            'approved_at' => $status === 'approved' ? $draft->approved_at?->toIso8601String() : null,
            'approved_by' => $status === 'approved' ? $draft->approved_by : null,
            'rejected_at' => $status === 'rejected' ? $draft->rejected_at?->toIso8601String() : null,
            'rejection_reason' => $status === 'rejected' ? $draft->rejection_reason : null,
            'updated_at' => $draft->updated_at->toIso8601String(),
            'can_review' => $workspace->owner_id === $user->id && $user->isOwnerOfWorkspace($workspace->id),
            'publication_status' => $draft->publication_status,
            'publication_error' => $draft->publication_error,
            'published_revision' => $draft->published_revision,
            'published_url' => $draft->published_url,
            'published_at' => $draft->published_at?->toIso8601String(),
        ];
    }

    private function assertNotPublishing(BlogDraft $draft): void
    {
        abort_if($draft->publication_status === 'publishing', 409, 'Wait for website publishing to finish before editing or reviewing this article.');
    }

    private function locked(BlogDraft $draft, Workspace $workspace): BlogDraft
    {
        return BlogDraft::query()->where('workspace_id', $workspace->id)->whereKey($draft->id)->lockForUpdate()->firstOrFail();
    }

    private function assertRevision(BlogDraft $draft, string $revision): void
    {
        if (! hash_equals($this->revision($draft), $revision)) {
            throw ValidationException::withMessages(['revision' => 'This draft or its website destination changed. Reload and review the latest version before continuing.']);
        }
    }

    private function assertUniqueSlug(Workspace $workspace, string $slug, ?string $exceptId = null): void
    {
        if (BlogDraft::withoutGlobalScope('workspace')->where('workspace_id', $workspace->id)->where('slug', $slug)
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Another blog draft in this workspace already uses that URL slug.']);
        }
    }

    private function ownerHasMembership(Workspace $workspace): bool
    {
        if ($workspace->getAttribute('owner_has_active_membership') !== null) {
            return (bool) $workspace->getAttribute('owner_has_active_membership');
        }

        return WorkspaceMembership::query()->where('workspace_id', $workspace->id)
            ->where('user_id', $workspace->owner_id)->where('role', WorkspaceRole::Owner->value)->exists();
    }

    /** @return array<string, null> */
    private function clearReview(): array
    {
        return [
            'review_requested_revision' => null, 'review_requested_at' => null,
            'approved_revision' => null, 'approved_by' => null, 'approved_at' => null,
            'rejected_revision' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
        ];
    }
}
