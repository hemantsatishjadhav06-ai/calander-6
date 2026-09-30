<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostReviewState;
use App\Models\PostWorkflowEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Creator\CreatorExportFreshness;
use App\Services\Posts\PostReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Staged decisions extend the existing whole-post revision and publishing gate. */
class StagedPostReviewService
{
    public function mode(Post $post): string
    {
        return (string) ($post->workspace()->value('review_mode') ?? 'off');
    }

    public function state(Post $post): ?PostReviewState
    {
        return PostReviewState::query()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->first();
    }

    public function status(Post $post): ?string
    {
        $mode = $this->mode($post);
        if ($mode === 'off') {
            return null;
        }

        $state = $this->state($post);
        if ($state?->on_hold) {
            return 'on_hold';
        }
        if (! $state?->revision) {
            return 'draft';
        }
        if ($state->mode !== $mode || $state->policy_version !== $this->policyVersion($post) || ! hash_equals($state->revision, app(PostReviewService::class)->revision($post))) {
            return 'stale';
        }
        if ($state->target_states === [] || ! $this->targetsMatch($post, $state)) {
            return 'draft';
        }

        foreach ($state->target_states as $target) {
            if ($target['internal'] === 'changes_requested' || ($mode === 'internal_client' && $target['client'] === 'changes_requested')) {
                return 'changes_requested';
            }
        }
        if (! $this->allApproved($state, 'internal')) {
            return 'awaiting_internal';
        }
        if ($mode === 'internal_client' && (! $this->validClient($post, $state->client_user_id) || ! $this->allApproved($state, 'client'))) {
            return 'awaiting_client';
        }

        return 'approved';
    }

    public function canClientView(Post $post, User $actor): bool
    {
        $state = $this->state($post);

        return $this->mode($post) === 'internal_client'
            && $state?->mode === 'internal_client'
            && $state->policy_version === $this->policyVersion($post)
            && $state->client_user_id === $actor->id
            && $this->validClient($post, $actor->id)
            && $state->revision !== null
            && hash_equals($state->revision, app(PostReviewService::class)->revision($post))
            && $this->targetsMatch($post, $state)
            && $this->allApproved($state, 'internal');
    }

    public function act(Post $post, User $actor, string $action, string $revision, ?string $note, string $source, ?string $targetId = null, ?string $stage = null): Post
    {
        $isClient = $actor->getMembershipForWorkspace($post->workspace_id)?->role === WorkspaceRole::Client;
        abort_unless($isClient || $actor->hasAllPermissions(['workspace.read'], $post->workspace_id), 403);
        $stage ??= $isClient ? 'client' : 'internal';
        abort_unless(in_array($stage, ['internal', 'client'], true), 422);
        if ($isClient) {
            abort_unless($stage === 'client' && in_array($action, ['approve', 'request_changes', 'comment', 'hold'], true), 403);
        } else {
            abort_unless($stage === 'internal', 403, 'Only the assigned client can make client-stage decisions.');
            if (! in_array($action, ['submit', 'comment'], true)) {
                abort_unless($actor->hasAllPermissions(['workspace.settings.manage'], $post->workspace_id), 403);
            }
        }
        if (in_array($action, ['comment', 'request_changes', 'hold'], true) && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Add a comment explaining this action.']);
        }

        return DB::transaction(function () use ($post, $actor, $action, $revision, $note, $source, $targetId, $stage, $isClient): Post {
            $locked = Post::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->lockForUpdate()->findOrFail($post->id);
            abort_unless(in_array($locked->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed, PostStatus::Missed], true), 422, 'This post is no longer available for review.');
            $mode = $this->mode($locked);
            abort_if($mode === 'off', 422, 'Staged reviews are disabled for this workspace.');
            $current = app(PostReviewService::class)->revision($locked);
            abort_unless(hash_equals($current, $revision), 409, 'The content changed. Reload and review the current revision.');
            if (in_array($action, ['submit', 'approve'], true) && app(CreatorExportFreshness::class)->hasStaleExports($locked)) {
                throw ValidationException::withMessages(['review' => 'Export the current design revision before requesting or granting approval.']);
            }
            if ($isClient) {
                abort_unless($this->canClientView($locked, $actor), 403, 'This revision is not assigned to you for client review.');
            }
            $state = $this->state($locked) ?? new PostReviewState(['workspace_id' => $locked->workspace_id, 'post_id' => $locked->id, 'mode' => $mode]);
            $targetIds = $locked->targets->pluck('id')->all();
            abort_if($targetId !== null && ! in_array($targetId, $targetIds, true), 404);
            $selected = $targetId === null ? $targetIds : [$targetId];
            if ($action === 'comment') {
                $this->record($locked, $actor, $action, $current, $note, $source, $stage, $targetId);

                return $locked;
            }
            if ($action === 'release_hold') {
                if (! $state->on_hold) {
                    return $locked;
                }
                $state->on_hold = false;
            } elseif ($action === 'hold') {
                $state->on_hold = true;
                $heldStates = [];
                foreach ($state->target_states as $id => $target) {
                    $heldStates[$id] = ['internal' => $stage === 'internal' ? 'pending' : $target['internal'], 'client' => 'pending'];
                }
                $state->target_states = $heldStates;
            } elseif ($action === 'submit') {
                abort_if($targetIds === [], 422, 'Choose at least one publishing destination before submitting for review.');
                if ($state->mode === $mode && $state->policy_version === $this->policyVersion($locked) && $state->revision === $current && $this->allHaveStatus($state, 'internal', 'pending')) {
                    return $locked;
                }
                $state->mode = $mode;
                $state->policy_version = $this->policyVersion($locked);
                $state->revision = $current;
                $state->target_states = array_fill_keys($targetIds, ['internal' => 'pending', 'client' => 'pending']);
            } elseif (in_array($action, ['approve', 'request_changes'], true)) {
                abort_if($state->on_hold, 422, 'Release the publishing hold before making a review decision.');
                abort_unless($state->revision === $current && $state->mode === $mode && $state->policy_version === $this->policyVersion($locked) && $this->targetsMatch($locked, $state), 422, 'Submit the current revision for review first.');
                $states = $state->target_states;
                $decision = $action === 'approve' ? 'approved' : 'changes_requested';
                $changed = false;
                foreach ($selected as $id) {
                    if ($states[$id][$stage] === $decision) {
                        continue;
                    }
                    if ($action === 'approve' && $states[$id][$stage] !== 'pending') {
                        throw ValidationException::withMessages(['review' => 'Resubmit the revision after addressing the requested changes.']);
                    }
                    $states[$id] = [
                        'internal' => $stage === 'internal' ? $decision : $states[$id]['internal'],
                        'client' => $stage === 'internal' ? 'pending' : $decision,
                    ];
                    $changed = true;
                }
                if (! $changed) {
                    return $locked;
                }
                $state->target_states = $states;
            } else {
                throw ValidationException::withMessages(['action' => 'Unknown review action.']);
            }
            $state->save();
            $locked->forceFill([
                'review_required' => true,
                'review_revision' => $current,
                'review_status' => $this->status($locked),
                'review_note' => $note,
            ])->save();
            $this->record($locked, $actor, $action, $current, $note, $source, $stage, $targetId);

            return $locked;
        });
    }

    public function assignClient(Post $post, User $actor, ?string $clientId, string $revision): void
    {
        abort_unless($actor->hasAllPermissions(['workspace.settings.manage'], $post->workspace_id), 403);
        DB::transaction(function () use ($post, $actor, $clientId, $revision): void {
            $locked = Post::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->lockForUpdate()->findOrFail($post->id);
            abort_unless(in_array($locked->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed, PostStatus::Missed], true), 422);
            abort_unless($this->mode($locked) === 'internal_client', 422);
            $current = app(PostReviewService::class)->revision($locked);
            abort_unless(hash_equals($current, $revision), 409, 'The content changed. Reload before assigning a reviewer.');
            abort_if($clientId !== null && ! $this->validClient($locked, $clientId), 422, 'Choose an existing client member of this workspace.');
            $state = $this->state($locked) ?? new PostReviewState(['workspace_id' => $locked->workspace_id, 'post_id' => $locked->id, 'mode' => 'internal_client']);
            if ($state->client_user_id === $clientId) {
                return;
            }
            $state->client_user_id = $clientId;
            $states = $state->target_states;
            foreach ($states as &$target) {
                $target['client'] = 'pending';
            }
            unset($target);
            $state->target_states = $states;
            $state->save();
            $locked->forceFill(['review_required' => true, 'review_status' => $this->status($locked), 'review_revision' => $current])->save();
            $this->record($locked, $actor, 'client_assigned', $current, null, 'reviews', 'internal', null);
        });
    }

    private function policyVersion(Post $post): int
    {
        return (int) $post->workspace()->value('review_policy_version');
    }

    private function validClient(Post $post, ?string $clientId): bool
    {
        return $clientId !== null && WorkspaceMembership::query()->where('workspace_id', $post->workspace_id)
            ->where('user_id', $clientId)->where('role', WorkspaceRole::Client->value)->exists();
    }

    private function targetsMatch(Post $post, PostReviewState $state): bool
    {
        $expected = $post->targets()->pluck('id')->sort()->values()->all();
        $actual = array_keys($state->target_states);
        sort($actual);

        return $expected !== [] && $expected === $actual;
    }

    private function allApproved(PostReviewState $state, string $stage): bool
    {
        return $this->allHaveStatus($state, $stage, 'approved');
    }

    private function allHaveStatus(PostReviewState $state, string $stage, string $status): bool
    {
        return $state->target_states !== [] && collect($state->target_states)->every(fn (array $target): bool => ($target[$stage] ?? null) === $status);
    }

    private function record(Post $post, User $actor, string $action, string $revision, ?string $note, string $source, string $stage, ?string $targetId): void
    {
        PostWorkflowEvent::create([
            'workspace_id' => $post->workspace_id, 'post_id' => $post->id, 'actor_id' => $actor->id,
            'source' => $source, 'action' => $action, 'revision' => $revision, 'note' => $note,
            'stage' => $stage, 'audience' => $stage === 'client' ? 'client' : 'internal', 'review_target_id' => $targetId,
        ]);
    }
}
