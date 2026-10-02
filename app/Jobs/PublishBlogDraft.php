<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BlogDraft;
use App\Models\Workspace;
use App\Services\Blogs\BlogDraftService;
use App\Services\Blogs\BlogPublicationException;
use App\Services\Blogs\BlogPublicationService;
use App\Services\Blogs\NetlifyBlogPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublishBlogDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $draftId,
        public readonly string $workspaceId,
        public readonly string $revision,
        public readonly string $attemptId,
        public readonly string $siteId,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('netlify-blog:'.$this->siteId)->shared()->releaseAfter(10)->expireAfter(960)];
    }

    public function handle(BlogPublicationService $publication, BlogDraftService $drafts, NetlifyBlogPublisher $publisher): void
    {
        try {
            $draft = DB::transaction(function () use ($publication): ?BlogDraft {
                $workspace = Workspace::query()->whereKey($this->workspaceId)->lockForUpdate()->first();
                $current = BlogDraft::withoutGlobalScopes()->where('workspace_id', $this->workspaceId)
                    ->whereKey($this->draftId)->lockForUpdate()->first();
                if ($workspace === null || $current === null || $current->publication_attempt_id !== $this->attemptId
                    || $current->publication_revision !== $this->revision || ! in_array($current->publication_status, ['queued', 'publishing'], true)) {
                    return null;
                }
                $publication->assertApproved($current, $workspace, $this->revision);
                $current->forceFill(['publication_status' => 'publishing'])->save();

                return $current;
            });
            if ($draft === null) {
                return;
            }
            if ($this->reconcile($publisher)) {
                return;
            }

            $destination = $drafts->destination($draft->workspace_id);
            if ($destination['netlify_site_id'] !== $this->siteId) {
                throw new BlogPublicationException('The website destination changed. Review this version again.');
            }
            $publisher->publish($draft, $destination, function (string $deployId, string $url, string $baseId, bool $wasLocked, callable $promote) use ($publication): void {
                $prepared = BlogDraft::withoutGlobalScopes()->whereKey($this->draftId)->where('publication_attempt_id', $this->attemptId)
                    ->where('publication_status', 'publishing')->update([
                        'publication_deploy_id' => $deployId, 'publication_deploy_attempt_id' => $this->attemptId,
                        'publication_url' => $url, 'publication_base_deploy_id' => $baseId, 'publication_base_was_locked' => $wasLocked,
                    ]);
                if ($prepared !== 1) {
                    throw new BlogPublicationException('This publishing attempt is no longer active.');
                }
                DB::transaction(function () use ($deployId, $url, $promote, $publication): void {
                    $workspace = Workspace::query()->whereKey($this->workspaceId)->lockForUpdate()->firstOrFail();
                    $current = BlogDraft::withoutGlobalScopes()->where('workspace_id', $this->workspaceId)
                        ->whereKey($this->draftId)->lockForUpdate()->firstOrFail();
                    if ($current->publication_attempt_id !== $this->attemptId || $current->publication_status !== 'publishing') {
                        throw new BlogPublicationException('This publishing attempt is no longer active.');
                    }
                    $publication->assertApproved($current, $workspace, $this->revision);
                    $current->forceFill(['publication_deploy_id' => $deployId])->save();
                    $promote(function () use ($current, $workspace, $publication): void {
                        $publication->assertApproved($current->refresh(), $workspace->refresh(), $this->revision);
                    });
                    $current->forceFill([
                        'publication_status' => 'published', 'publication_error' => null,
                        'published_revision' => $this->revision, 'published_url' => $url, 'published_at' => now(),
                    ])->save();
                });
            });
        } catch (ValidationException $exception) {
            if (! $this->reconcile($publisher)) {
                $this->fail($publication, $publisher, $exception->errors()['publication'][0] ?? 'Fresh owner approval is required.');
            }
        } catch (BlogPublicationException $exception) {
            if (! $this->reconcile($publisher)) {
                $this->fail($publication, $publisher, $exception->getMessage());
            }
        } catch (Throwable) {
            if (! $this->reconcile($publisher)) {
                $this->fail($publication, $publisher, 'Website publishing did not finish. Check the website and Netlify deployment lock before retrying.');
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $publisher = app(NetlifyBlogPublisher::class);
        if (! $this->reconcile($publisher)) {
            $this->fail(app(BlogPublicationService::class), $publisher,
                'Website publishing did not finish. Check the website and Netlify deployment lock before retrying.');
        }
    }

    private function fail(BlogPublicationService $publication, NetlifyBlogPublisher $publisher, string $message): void
    {
        try {
            $draft = BlogDraft::withoutGlobalScopes()->whereKey($this->draftId)->where('workspace_id', $this->workspaceId)
                ->where('publication_attempt_id', $this->attemptId)->where('publication_deploy_attempt_id', $this->attemptId)->first();
            if ($draft !== null && $draft->publication_deploy_id !== null && $draft->publication_base_deploy_id !== null && $draft->publication_base_was_locked !== null) {
                $publisher->restoreLockState($this->siteId, $draft->publication_base_deploy_id,
                    $draft->publication_deploy_id, $draft->publication_base_was_locked);
            }
        } catch (Throwable) {
            $message .= ' Netlify auto-publishing settings need attention. Check the deployment lock before retrying.';
        }
        $publication->fail($this->draftId, $this->attemptId, $message);
    }

    private function reconcile(NetlifyBlogPublisher $publisher): bool
    {
        try {
            $draft = BlogDraft::withoutGlobalScopes()->whereKey($this->draftId)->where('workspace_id', $this->workspaceId)
                ->where('publication_attempt_id', $this->attemptId)->where('publication_deploy_attempt_id', $this->attemptId)->first();
            if ($draft === null || $draft->publication_deploy_id === null || $draft->publication_url === null
                || $draft->publication_revision !== $this->revision || ! $publisher->isPublished($this->siteId, $draft->publication_deploy_id)) {
                return false;
            }
            $draft->forceFill([
                'publication_status' => 'published', 'published_revision' => $this->revision,
                'published_url' => $draft->publication_url, 'published_at' => $draft->published_at ?? now(), 'publication_error' => null,
            ])->save();
            try {
                if ($draft->publication_base_deploy_id !== null && $draft->publication_base_was_locked !== null) {
                    $publisher->restoreLockState($this->siteId, $draft->publication_base_deploy_id,
                        $draft->publication_deploy_id, $draft->publication_base_was_locked);
                }
            } catch (Throwable) {
                $draft->forceFill(['publication_error' => 'The article is live, but Netlify auto-publishing settings need attention. Check the deployment lock in Netlify.'])->save();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
