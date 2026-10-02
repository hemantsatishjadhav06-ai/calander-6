<?php

use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreateShareLinkTool;
use App\Mcp\Tools\RemovePostMediaTool;
use App\Models\BlogDraft;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostShare;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Posts\PostApprovalService;
use App\Services\Posts\ShareService;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Bus::fake();
    config()->set('subscriptions.enabled', false);
});

function reviewablePost(): array
{
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $owner->id]);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => 'x', 'token_expires_at' => now()->addHour()]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => [$post->base_text]]);

    return [$owner, $workspace, $post, $target];
}

function approveReviewedPost(Post $post): string
{
    $revision = app(PostApprovalService::class)->revision($post);
    test()->postJson(route('posts.review.request', $post), ['revision' => $revision])->assertSuccessful()->assertJsonPath('post.approval.status', 'awaiting_approval');
    test()->postJson(route('posts.review.approve', $post), ['revision' => $revision])->assertSuccessful()->assertJsonPath('post.approval.status', 'approved');

    return $revision;
}

test('owner can request approve reject and revoke a persisted draft review', function () {
    [$owner, , $post] = reviewablePost();
    $revision = approveReviewedPost($post);
    expect($post->refresh()->approved_by)->toBe($owner->id)
        ->and($post->approved_at)->not->toBeNull()
        ->and($post->approved_revision)->toBe($revision);
    $this->postJson(route('posts.review.reject', $post), ['revision' => $revision, 'reason' => 'Revise the headline'])->assertSuccessful()->assertJsonPath('post.approval.status', 'rejected')->assertJsonPath('post.approval.rejection_reason', 'Revise the headline');
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse();
    $this->postJson(route('posts.review.revoke', $post), ['revision' => $revision])->assertSuccessful()->assertJsonPath('post.approval.status', 'draft');
});

test('a member may request review but cannot approve reject or revoke', function () {
    [, $workspace, $post] = reviewablePost();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $workspace->members()->create(['user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->actingAs($member);
    $revision = app(PostApprovalService::class)->revision($post);
    $this->postJson(route('posts.review.request', $post), ['revision' => $revision])->assertSuccessful();
    foreach (['approve', 'reject', 'revoke'] as $action) {
        $this->postJson(route('posts.review.'.$action, $post), ['revision' => $revision])->assertForbidden();
    }
});

test('stale review returns the current post and cannot approve unseen edits', function () {
    [, , $post] = reviewablePost();
    $revision = app(PostApprovalService::class)->revision($post);
    $this->postJson(route('posts.review.request', $post), ['revision' => $revision])->assertSuccessful();
    $post->update(['segments' => ['Changed after review'], 'base_text' => 'Changed after review']);
    $this->postJson(route('posts.review.approve', $post), ['revision' => $revision])->assertStatus(409)->assertJsonPath('post.base_text', 'Changed after review')->assertJsonPath('post.approval.status', 'draft');
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse();
});

test('approval requires a review request and foreign workspace reviews fail closed', function () {
    [, , $post] = reviewablePost();
    $revision = app(PostApprovalService::class)->revision($post);
    $this->postJson(route('posts.review.approve', $post), ['revision' => $revision])->assertStatus(409);
    ownerActingIn();
    $this->postJson(route('posts.review.request', $post), ['revision' => $revision])->assertNotFound();
});

test('review fingerprint excludes delivery progress and metric timestamps', function () {
    [, , $post, $target] = reviewablePost();
    $revision = approveReviewedPost($post);
    $post->forceFill(['updated_at' => now()->addMinute(), 'status' => PostStatus::Publishing])->save();
    $target->forceFill(['attempts' => 3, 'remote_ids' => ['id'], 'metrics_captured_at' => now(), 'likes' => 10, 'media_upload_state' => ['polls' => 2]])->save();
    expect(app(PostApprovalService::class)->revision($post))->toBe($revision)
        ->and(app(PostApprovalService::class)->isApproved($post))->toBeTrue();
});

test('publishable content and destination changes invalidate approval', function (string $change) {
    [, , $post, $target] = reviewablePost();
    Storage::fake('local');
    $media = PostMedia::factory()->create(['workspace_id' => $post->workspace_id, 'post_id' => $post->id, 'disk' => 'local', 'path' => 'original.jpg']);
    $placement = PostMediaPlacement::create(['post_target_id' => $target->id, 'post_media_id' => $media->id, 'segment_ref' => '__head__', 'position' => 0]);
    approveReviewedPost($post);
    match ($change) {
        'text' => $post->update(['base_text' => 'Changed']),
        'sections' => $target->update(['sections' => ['Changed']]),
        'format' => $target->update(['format' => PostFormat::Story]),
        'placement' => $placement->update(['position' => 1]),
        'alt' => $media->update(['alt_text' => 'Changed']),
        'bytes' => $media->update(['path' => 'replaced.jpg']),
        'destination' => $target->account()->firstOrFail()->update(['remote_account_id' => 'different-account']),
        'schedule' => $post->forceFill(['planned_schedule_at' => now()->addDay()])->save(),
        'repost' => $post->update(['auto_repost' => true]),
        'remove_media' => $media->delete(),
    };
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse()
        ->and(app(PostApprovalService::class)->toView($post)['status'])->toBe('draft');
})->with(['text', 'sections', 'format', 'placement', 'alt', 'bytes', 'destination', 'schedule', 'repost', 'remove_media']);

test('unapproved direct publication jobs never call a connector', function () {
    [, , $post, $target] = reviewablePost();
    bindConnector(fn () => throw new RuntimeException('Unapproved connector call'));
    new PublishPostTarget($target)->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->attempts)->toBe(0)
        ->and($target->error_message)->toContain('approve')
        ->and($post->refresh()->status)->toBe(PostStatus::Failed);
});

test('approved publication claim blocks content and review mutation during connector execution', function () {
    [, , $post, $target] = reviewablePost();
    $revision = approveReviewedPost($post);
    bindConnector(function () use ($post, $revision) {
        test()->postJson(route('posts.review.revoke', $post), ['revision' => $revision])->assertStatus(409);
        test()->putJson(route('posts.update', $post), ['segments' => ['Changed'], 'destination' => ['kind' => 'none']])->assertUnprocessable();

        return PublishResult::success(['approved-remote-id']);
    });
    new PublishPostTarget($target)->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($target->refresh()->status)->toBe(PostTargetStatus::Published)
        ->and($post->refresh()->base_text)->not->toBe('Changed');
});

test('provider identity change during credential resolution is checked before connector effect', function () {
    [, , $post, $target] = reviewablePost();
    approveReviewedPost($post);
    $this->mock(TokenManager::class)->shouldReceive('fresh')->once()->andReturnUsing(function () use ($target) {
        $target->account()->firstOrFail()->update(['remote_account_id' => 'swapped-after-review']);

        return ['access_token' => 'fake'];
    });
    bindConnector(fn () => throw new RuntimeException('Stale identity connector call'));
    new PublishPostTarget($target)->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_message)->toContain('approve');
});

test('another draft route cannot mutate media of a publishing post', function () {
    [, , $post, $target] = reviewablePost();
    $media = PostMedia::factory()->create(['workspace_id' => $post->workspace_id, 'post_id' => $post->id]);
    $post->update(['status' => PostStatus::Publishing]);
    $target->update(['status' => PostTargetStatus::Publishing]);
    $dummy = Post::factory()->create(['workspace_id' => $post->workspace_id, 'author_id' => $post->author_id]);
    $this->patchJson(route('posts.media.alt', [$dummy, $media]), ['alt_text' => 'Bypass'])->assertNotFound();
    $this->deleteJson(route('posts.media.destroy', [$dummy, $media]))->assertNotFound();
    expect($media->refresh()->alt_text)->not->toBe('Bypass');
});

test('planning a new time remains a private draft and invalidates its review', function () {
    [, , $post] = reviewablePost();
    $revision = approveReviewedPost($post);
    $at = now()->addDays(2)->startOfSecond()->toIso8601String();
    $this->putJson(route('posts.review.plan', $post), ['revision' => $revision, 'scheduled_at' => $at])->assertSuccessful()->assertJsonPath('post.approval.status', 'draft')->assertJsonPath('post.status', 'draft')->assertJsonPath('post.scheduled_at', null);
    expect($post->refresh()->planned_schedule_at->toIso8601String())->toBe($at)
        ->and($post->scheduled_at)->toBeNull();
});

test('unapproved draft cannot mint a public share and existing links hide unreviewed revisions', function () {
    [$owner, , $post] = reviewablePost();
    $this->postJson(route('posts.shares.store', $post))->assertUnprocessable()->assertJsonValidationErrors('approval');
    $token = str_repeat('a', 43);
    PostShare::create(['post_id' => $post->id, 'created_by' => $owner->id, 'token_hash' => hash('sha256', $token)]);
    $this->get(route('share.show', $token))->assertInertia(fn (Assert $page) => $page->where('post', null));
    approveReviewedPost($post);
    $this->get(route('share.show', $token))->assertInertia(fn (Assert $page) => $page->where('post.base_text', $post->base_text));
    $post->update(['base_text' => 'Unreviewed change']);
    $this->get(route('share.show', $token))->assertInertia(fn (Assert $page) => $page->where('post', null));
});

test('approved public preview resolves the reviewed accounts outside the visitors workspace', function () {
    [$owner, , $post] = reviewablePost();
    approveReviewedPost($post);
    [, $token] = app(ShareService::class)->mint($post, $owner, null);
    ownerActingIn();
    $this->get(route('share.show', $token))->assertInertia(fn (Assert $page) => $page->where('post.base_text', $post->base_text));
});

test('changing and reverting reviewed content never restores its old approval', function () {
    [, , $post, $target] = reviewablePost();
    $original = $post->base_text;
    approveReviewedPost($post);
    $post->update(['base_text' => 'Temporary edit']);
    $post->update(['base_text' => $original]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse();
    approveReviewedPost($post);
    $account = $target->account()->firstOrFail();
    $originalId = $account->remote_account_id;
    $account->update(['remote_account_id' => 'changed-identity']);
    $account->update(['remote_account_id' => $originalId]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse();
});

test('identical autosave preserves the reviewed revision', function () {
    [, , , $target] = reviewablePost();
    $payload = ['destination' => ['kind' => 'account', 'id' => $target->connected_account_id], 'segments' => ['Reviewed headline']];
    $response = $this->postJson(route('posts.store'), $payload)->assertCreated();
    $post = Post::findOrFail($response->json('post.id'));
    $revision = approveReviewedPost($post);
    $this->putJson(route('posts.update', $post), $payload)->assertSuccessful()->assertJsonPath('post.approval.status', 'approved')->assertJsonPath('post.approval.revision', $revision);
});

test('approval is checked again before the connector auth retry', function () {
    [, , $post, $target] = reviewablePost();
    approveReviewedPost($post);
    $calls = 0;
    $this->mock(TokenManager::class)->shouldReceive('fresh')->twice()->andReturnUsing(function () use ($target, &$calls) {
        if (++$calls === 2) {
            $target->account()->firstOrFail()->update(['remote_account_id' => 'changed-during-refresh']);
        }

        return ['access_token' => 'fake'];
    });
    $publishCalls = 0;
    bindConnector(function () use (&$publishCalls) {
        $publishCalls++;

        return PublishResult::failure(ErrorKind::AuthExpired, 'Refresh credentials');
    });
    new PublishPostTarget($target)->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($publishCalls)->toBe(1)
        ->and($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_message)->toContain('approve');
});

test('temporary credentials refresh preserves approval but a destination transport change clears it', function () {
    [, , $post, $target] = reviewablePost();
    $secret = $target->account()->firstOrFail()->secret()->firstOrFail();
    $secret->update(['session' => ['pds' => 'https://pds.example.com', 'accessJwt' => 'old']]);
    approveReviewedPost($post);
    $secret->update(['session' => ['pds' => 'https://pds.example.com', 'accessJwt' => 'fresh']]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeTrue();
    $secret->update(['session' => ['pds' => 'https://another.example.com', 'accessJwt' => 'fresh']]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse();
    $secret->update(['session' => ['pds' => 'https://pds.example.com', 'accessJwt' => 'fresh']]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse();
});

test('a dummy draft cannot steal attachments from a publishing post', function () {
    [, , $post, $target] = reviewablePost();
    $media = PostMedia::factory()->create(['workspace_id' => $post->workspace_id, 'post_id' => $post->id]);
    $post->update(['status' => PostStatus::Publishing]);
    $target->update(['status' => PostTargetStatus::Publishing]);
    $dummy = Post::factory()->create(['workspace_id' => $post->workspace_id, 'author_id' => $post->author_id]);
    $this->putJson(route('posts.update', $dummy), ['destination' => ['kind' => 'none'], 'segments' => ['Dummy'], 'media_ids' => [$media->id]])->assertSuccessful()->assertJsonCount(0, 'post.media');
    expect($media->refresh()->post_id)->toBe($post->id);
});

test('API media deletion cannot mutate a publishing post', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $user->id, 'status' => PostStatus::Publishing]);
    $media = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $post->id]);
    $this->withToken($token)->deleteJson('/api/v1/media/'.$media->id)->assertUnprocessable();
    expect($media->fresh())->not->toBeNull();
});

test('MCP cannot remove publishing attachments or share unapproved drafts', function () {
    [$owner, $workspace, $post] = reviewablePost();
    bindTokenToWorkspace($owner, $workspace);
    ShoutrrrServer::actingAs($owner)->tool(CreateShareLinkTool::class, ['post_id' => $post->id])->assertHasErrors();
    $post->update(['status' => PostStatus::Publishing]);
    $media = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => $post->id]);
    ShoutrrrServer::actingAs($owner)->tool(RemovePostMediaTool::class, ['media_id' => $media->id])->assertHasErrors();
    expect($media->fresh())->not->toBeNull();
});

test('requesting review again cancels an existing schedule until owner approval', function () {
    [, , $post] = reviewablePost();
    $at = now()->addDay()->startOfSecond();
    $post->forceFill(['planned_schedule_at' => $at])->save();
    $revision = approveReviewedPost($post);
    $this->putJson(route('posts.schedule', $post), ['scheduled_at' => $at->toIso8601String()])->assertSuccessful();
    $this->postJson(route('posts.review.request', $post), ['revision' => $revision])->assertSuccessful()->assertJsonPath('post.status', 'draft')->assertJsonPath('post.scheduled_at', null)->assertJsonPath('post.approval.status', 'awaiting_approval');
});

test('review authority requires a live owner membership and rejoining does not restore old approval', function () {
    [$owner, $workspace, $post] = reviewablePost();
    approveReviewedPost($post);
    WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->delete();
    $view = app(PostApprovalService::class)->toView($post->fresh(), $owner);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse()
        ->and($view['status'])->not->toBe('approved')
        ->and($view['can_approve'])->toBeFalse();
    WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse()
        ->and($post->refresh()->approved_revision)->toBeNull();
});

test('owner transfer and membership demotion clear both social and blog approval permanently', function () {
    [$owner, $workspace, $post] = reviewablePost();
    approveReviewedPost($post);
    $blog = BlogDraft::factory()->create(['workspace_id' => $workspace->id]);
    $blog->forceFill(['approved_revision' => str_repeat('b', 64), 'approved_by' => $owner->id, 'approved_at' => now()])->save();
    $other = User::factory()->create();
    $workspace->update(['owner_id' => $other->id]);
    $workspace->update(['owner_id' => $owner->id]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse()
        ->and($blog->refresh()->approved_revision)->toBeNull()
        ->and($blog->content_revision)->toBe(3);
    approveReviewedPost($post);
    $membership = $workspace->members()->where('user_id', $owner->id)->firstOrFail();
    $membership->update(['role' => WorkspaceRole::Admin]);
    $membership->update(['role' => WorkspaceRole::Owner]);
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse()
        ->and($post->refresh()->approved_revision)->toBeNull();
});

test('public shares render only the exact content snapshot that passed approval', function () {
    [$owner, , $post] = reviewablePost();
    $token = str_repeat('p', 43);
    PostShare::create(['post_id' => $post->id, 'created_by' => $owner->id, 'token_hash' => hash('sha256', $token)]);
    $original = app(PostApprovalService::class);
    $this->partialMock(PostApprovalService::class)->shouldReceive('isApproved')->once()->andReturnUsing(function () use ($post, $owner, $original) {
        $post->update(['base_text' => 'Newly reviewed text']);
        $revision = $original->revision($post);
        $original->review($post, $owner, 'request', $revision);
        $original->review($post, $owner, 'approve', $revision);

        return true;
    });
    $this->get(route('share.show', $token))->assertInertia(fn (Assert $page) => $page->where('post', null));
});

test('owner authority and approval policy cannot change during an approved connector operation', function () {
    [$owner, $workspace, $post, $target] = reviewablePost();
    approveReviewedPost($post);
    $other = User::factory()->create();
    $membership = $workspace->members()->create(['user_id' => $other->id, 'role' => WorkspaceRole::Member]);
    bindConnector(function () use ($owner, $workspace, $membership) {
        test()->postJson(route('workspaces.transfer', $workspace), ['membership_id' => $membership->id])->assertStatus(409);
        expect($workspace->refresh()->owner_id)->toBe($owner->id);
        expect(fn () => $workspace->forceFill(['requires_post_approval' => false])->save())->toThrow(HttpException::class);

        return PublishResult::success(['approved-remote-id']);
    });
    new PublishPostTarget($target)->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($target->refresh()->status)->toBe(PostTargetStatus::Published)
        ->and($workspace->refresh()->owner_id)->toBe($owner->id)
        ->and($workspace->requires_post_approval)->toBeTrue();
});

test('a sole owner cannot leave during an approved connector operation', function () {
    [$owner, $workspace, $post, $target] = reviewablePost();
    approveReviewedPost($post);
    bindConnector(function () use ($owner, $workspace) {
        test()->deleteJson(route('workspaces.leave', $workspace))->assertStatus(409);
        expect($workspace->members()->where('user_id', $owner->id)->exists())->toBeTrue();

        return PublishResult::success(['approved-remote-id']);
    });
    new PublishPostTarget($target)->handle(app(PublishConnectorRegistry::class), app(TokenManager::class), app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($target->refresh()->status)->toBe(PostTargetStatus::Published);
});

test('changing and restoring the approval policy never restores old social or blog approvals', function () {
    [$owner, $workspace, $post] = reviewablePost();
    approveReviewedPost($post);
    $blog = BlogDraft::factory()->create(['workspace_id' => $workspace->id]);
    $blog->forceFill(['approved_revision' => str_repeat('b', 64), 'approved_by' => $owner->id, 'approved_at' => now()])->save();
    $workspace->forceFill(['requires_post_approval' => false])->save();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    expect(app(PostApprovalService::class)->isApproved($post))->toBeFalse()
        ->and($post->refresh()->approved_revision)->toBeNull()
        ->and($blog->refresh()->approved_revision)->toBeNull()
        ->and($blog->content_revision)->toBe(3);
});
