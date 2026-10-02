<?php

declare(strict_types=1);

namespace App\Services\Blogs;

use App\Enums\WorkspaceRole;
use App\Jobs\PublishBlogDraft;
use App\Models\BlogDraft;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlogPublicationService
{
    public function __construct(private readonly BlogDraftService $drafts) {}

    /**
     * @param  array<string, string|null>  $destination
     * @return array{available: bool, reason: string}
     */
    public function availability(array $destination, Workspace $workspace): array
    {
        $siteId = $destination['netlify_site_id'] ?? null;
        $website = rtrim((string) ($destination['website_url'] ?? ''), '/');
        /** @var array<string, string> $sites */
        $sites = config('blogs.sites', []);
        $ownerEmail = (string) config('blogs.owner_email');
        $available = (bool) config('blogs.publishing_enabled') && filled(config('services.netlify.token'))
            && $ownerEmail !== '' && User::query()->whereKey($workspace->owner_id)->where('email', $ownerEmail)->exists()
            && $siteId !== null && isset($sites[$siteId]) && $sites[$siteId] === $website;

        return [
            'available' => $available,
            'reason' => $available
                ? 'Your verified website is connected. The owner can publish an approved version explicitly.'
                : 'Website publishing needs a verified site connection. You can save, preview, and approve private drafts now.',
        ];
    }

    public function request(User $user, BlogDraft $draft, string $revision): void
    {
        DB::transaction(function () use ($user, $draft, $revision): void {
            $workspace = $this->drafts->workspace($user, ownerOnly: true, lock: true);
            $current = BlogDraft::query()->where('workspace_id', $workspace->id)->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->assertApproved($current, $workspace, $revision);
            if ($current->published_revision === $revision || in_array($current->publication_status, ['queued', 'publishing'], true)) {
                return;
            }

            $attempt = (string) Str::uuid();
            $current->forceFill([
                'publication_status' => 'queued', 'publication_attempt_id' => $attempt,
                'publication_revision' => $revision, 'publication_error' => null,
            ])->save();
            PublishBlogDraft::dispatch($current->id, $workspace->id, $revision, $attempt,
                (string) $this->drafts->destination($workspace->id)['netlify_site_id'])->afterCommit();
        });
    }

    public function assertApproved(BlogDraft $draft, Workspace $workspace, string $revision): void
    {
        if (! hash_equals($this->drafts->revision($draft), $revision)
            || $draft->approved_revision !== $revision || $draft->review_requested_revision !== $revision
            || $draft->approved_at === null || $draft->approved_by !== $workspace->owner_id
            || ! WorkspaceMembership::query()->where('workspace_id', $workspace->id)
                ->where('user_id', $workspace->owner_id)->where('role', WorkspaceRole::Owner->value)->exists()) {
            throw ValidationException::withMessages(['publication' => 'The owner must approve the current content and website destination before publishing.']);
        }
        if (! $this->availability($this->drafts->destination($workspace->id), $workspace)['available']) {
            throw ValidationException::withMessages(['publication' => 'Website publishing is not connected for this workspace and destination.']);
        }
    }

    public function fail(string $id, string $attempt, string $message): void
    {
        BlogDraft::withoutGlobalScopes()->whereKey($id)->where('publication_attempt_id', $attempt)
            ->whereIn('publication_status', ['queued', 'publishing'])->update([
                'publication_status' => 'failed', 'publication_error' => $message,
            ]);
    }
}
