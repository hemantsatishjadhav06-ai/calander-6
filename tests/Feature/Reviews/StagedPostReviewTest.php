<?php

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostWorkflowEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Creator\CreatorExportFreshness;
use App\Services\Posts\PostReviewService;
use App\Services\Publishing\PublishDispatcher;
use App\Services\Reviews\StagedPostReviewService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->owner, $this->workspace] = ownerActingIn();
    $this->workspace->forceFill(['review_mode' => 'internal_client'])->save();
    $this->post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'author_id' => $this->owner->id, 'segments' => ['Review this caption'], 'base_text' => 'Review this caption']);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->target = PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Review this caption']]);
    $this->client = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->client->id, 'role' => WorkspaceRole::Client]);
    $this->staged = app(StagedPostReviewService::class);
    $this->reviews = app(PostReviewService::class);
    $this->revision = $this->reviews->revision($this->post);
});

function stagedSubmitAndAssign(object $test): void
{
    $test->staged->assignClient($test->post, $test->owner, $test->client->id, $test->revision);
    $test->reviews->act($test->post, $test->owner, 'submit', $test->revision, null, 'reviews');
}

function stagedApproveInternally(object $test): void
{
    stagedSubmitAndAssign($test);
    $test->reviews->act($test->post, $test->owner, 'approve', $test->revision, null, 'reviews');
}

it('requires all configured stages without changing legacy optional review behavior', function () {
    expect($this->reviews->status($this->post))->toBe('draft')->and($this->reviews->canPublish($this->post))->toBeFalse();
    $this->workspace->forceFill(['review_mode' => 'off'])->save();
    expect($this->reviews->status($this->post))->toBe('not_required')->and($this->reviews->canPublish($this->post))->toBeTrue();
    $this->reviews->act($this->post, $this->owner, 'submit', $this->revision, null, 'reviews');
    expect($this->reviews->status($this->post->fresh()))->toBe('pending');
    $this->reviews->act($this->post, $this->owner, 'approve', $this->revision, null, 'reviews');
    expect($this->reviews->canPublish($this->post->fresh()))->toBeTrue();
});

it('requires internal approval before assigned client review and never substitutes an admin for the client', function () {
    stagedSubmitAndAssign($this);
    expect($this->reviews->status($this->post))->toBe('awaiting_internal');
    $this->actingAs($this->client)->postJson(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'client'])->assertNotFound();
    $this->actingAs($this->owner);
    $this->reviews->act($this->post, $this->owner, 'approve', $this->revision, null, 'reviews');
    expect($this->reviews->status($this->post))->toBe('awaiting_client')->and($this->reviews->canPublish($this->post))->toBeFalse();
    $this->postJson(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'client'])->assertForbidden();
    $this->actingAs($this->client)->post(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'client'])->assertRedirect();
    expect($this->reviews->status($this->post))->toBe('approved')->and($this->reviews->canPublish($this->post))->toBeTrue();
});

it('tracks per-account decisions but blocks the entire post until every account is approved', function () {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id]);
    $this->revision = $this->reviews->revision($this->post);
    stagedSubmitAndAssign($this);
    $this->staged->act($this->post, $this->owner, 'approve', $this->revision, null, 'reviews', $this->target->id);
    expect($this->reviews->status($this->post))->toBe('awaiting_internal');
    $this->staged->act($this->post, $this->owner, 'approve', $this->revision, null, 'reviews', $second->id);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews', $this->target->id);
    expect($this->reviews->canPublish($this->post))->toBeFalse();
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews', $second->id);
    expect($this->reviews->canPublish($this->post))->toBeTrue();
});

it('supports internal-only workflow without an assigned client', function () {
    $this->workspace->forceFill(['review_mode' => 'internal'])->save();
    $this->reviews->act($this->post, $this->owner, 'submit', $this->revision, null, 'reviews');
    $this->reviews->act($this->post, $this->owner, 'approve', $this->revision, null, 'reviews');
    expect($this->reviews->canPublish($this->post))->toBeTrue();
});

it('records authenticated change requests and comments while keeping internal notes private', function () {
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->owner, 'comment', $this->revision, 'Private production detail', 'reviews');
    $this->staged->act($this->post, $this->client, 'request_changes', $this->revision, 'Please shorten the caption', 'reviews');
    expect($this->reviews->status($this->post))->toBe('changes_requested')->and($this->reviews->canPublish($this->post))->toBeFalse();
    $this->actingAs($this->client)->getJson(route('reviews.history', $this->post))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.note', 'Please shorten the caption');
    $this->actingAs($this->owner)->getJson(route('reviews.history', $this->post))->assertOk()->assertJsonCount(5, 'data');
    expect(PostWorkflowEvent::query()->where('action', 'request_changes')->value('actor_id'))->toBe($this->client->id);
});

it('resubmission clears both stages and denies approval directly after requested changes', function () {
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->client, 'request_changes', $this->revision, 'Needs changes', 'reviews');
    $this->actingAs($this->client)->postJson(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'client'])->assertUnprocessable();
    $this->reviews->act($this->post, $this->owner, 'submit', $this->revision, 'Updated for review', 'reviews');
    expect($this->reviews->status($this->post))->toBe('awaiting_internal');
    expect($this->staged->state($this->post)->target_states[$this->target->id])->toBe(['internal' => 'pending', 'client' => 'pending']);
});

it('holds publishing and requires fresh stage approval after an admin releases the hold', function () {
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    $this->staged->act($this->post, $this->client, 'hold', $this->revision, 'Wait for launch confirmation', 'reviews');
    expect($this->reviews->status($this->post))->toBe('on_hold')->and($this->reviews->canPublish($this->post))->toBeFalse();
    $this->actingAs($this->client)->postJson(route('reviews.act', $this->post), ['action' => 'release_hold', 'revision' => $this->revision, 'stage' => 'client'])->assertForbidden();
    $this->staged->act($this->post, $this->owner, 'release_hold', $this->revision, null, 'reviews');
    expect($this->reviews->status($this->post))->toBe('awaiting_client');
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    expect($this->reviews->canPublish($this->post))->toBeTrue();
});

it('invalidates all account decisions after caption media or destination changes', function (string $change) {
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    match ($change) {
        'caption' => $this->post->forceFill(['segments' => ['Changed caption']])->save(),
        'override' => $this->target->forceFill(['content_override' => ['text' => 'Changed override']])->save(),
        'media' => PostMedia::factory()->create(['post_id' => $this->post->id, 'workspace_id' => $this->workspace->id]),
        'target' => PostTarget::factory()->for($this->post)->create(),
    };
    expect($this->reviews->status($this->post->fresh()))->toBe('stale')->and($this->reviews->canPublish($this->post->fresh()))->toBeFalse();
    $this->postJson(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'internal'])->assertConflict();
    $this->actingAs($this->client)->getJson(route('reviews.history', $this->post))->assertNotFound();
})->with(['caption', 'override', 'media', 'target']);

it('binds staged approvals to creator scene freshness and refuses stale exports', function () {
    $sceneRevision = 1;
    $freshness = Mockery::mock(CreatorExportFreshness::class);
    $freshness->shouldReceive('snapshot')->andReturnUsing(fn () => [['project_id' => 'test-project', 'current_revision' => $sceneRevision]]);
    $freshness->shouldReceive('hasStaleExports')->andReturn(false);
    app()->instance(CreatorExportFreshness::class, $freshness);
    $this->revision = $this->reviews->revision($this->post);
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    $freshness = Mockery::mock(CreatorExportFreshness::class);
    $freshness->shouldReceive('snapshot')->andReturn([['project_id' => 'test-project', 'current_revision' => 2]]);
    $freshness->shouldReceive('hasStaleExports')->andReturn(true);
    app()->instance(CreatorExportFreshness::class, $freshness);
    expect($this->reviews->status($this->post))->toBe('stale')->and($this->reviews->canPublish($this->post))->toBeFalse();
    $current = $this->reviews->revision($this->post);
    $this->postJson(route('reviews.act', $this->post), ['action' => 'submit', 'revision' => $current, 'stage' => 'internal'])->assertUnprocessable();
});

it('does not resurrect approvals when a policy is changed and changed back', function () {
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    $this->put(route('reviews.configure'), ['mode' => 'internal', 'expected_workspace_id' => $this->workspace->id])->assertRedirect();
    $this->put(route('reviews.configure'), ['mode' => 'internal_client', 'expected_workspace_id' => $this->workspace->id])->assertRedirect();
    expect($this->reviews->status($this->post))->toBe('stale')->and($this->reviews->canPublish($this->post))->toBeFalse();
});

it('invalidates client approval on reassignment and denies removed client memberships', function () {
    stagedApproveInternally($this);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    $this->staged->assignClient($this->post, $this->owner, null, $this->revision);
    expect($this->reviews->status($this->post))->toBe('awaiting_client');
    $this->staged->assignClient($this->post, $this->owner, $this->client->id, $this->revision);
    $this->staged->act($this->post, $this->client, 'approve', $this->revision, null, 'reviews');
    WorkspaceMembership::query()->where('user_id', $this->client->id)->delete();
    expect($this->reviews->canPublish($this->post))->toBeFalse();
});

it('rejects a foreign user or non-client member as the client reviewer', function () {
    foreach ([$this->owner->id, User::factory()->create()->id] as $id) {
        $this->putJson(route('reviews.assign', $this->post), ['client_user_id' => $id, 'revision' => $this->revision])->assertUnprocessable();
    }
});

it('only exposes assigned internally approved revisions to client members with no application shell data', function () {
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'base_text' => 'Unassigned secret draft']);
    stagedApproveInternally($this);
    $this->actingAs($this->client)->get(route('reviews.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('reviews/client')->has('posts.data', 1)->where('posts.data.0.id', $this->post->id)->where('clients', [])
        ->missing('shell')->missing('workspaces')->missing('billing')->missing('notifications')->where('canManage', false));
    $this->get(route('dashboard'))->assertRedirect(route('reviews.index'));
});

it('denies clients publishing editing administration and all unassigned or cross-workspace reviews', function () {
    stagedApproveInternally($this);
    $other = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreign = Post::factory()->create();
    $this->actingAs($this->client);
    foreach ([$other, $foreign] as $post) {
        $this->get(route('reviews.index', ['post' => $post->id]))->assertNotFound();
        $this->getJson(route('reviews.history', $post))->assertNotFound();
    }
    $this->get(route('posts.show', $this->post))->assertForbidden();
    $this->postJson(route('posts.publish', $this->post))->assertForbidden();
    $this->putJson(route('reviews.configure'), ['mode' => 'off'])->assertForbidden();
    $this->get(route('settings.workspace.members'))->assertForbidden();
    $this->postJson(route('reviews.act', $this->post), ['action' => 'submit', 'revision' => $this->revision, 'stage' => 'internal'])->assertForbidden();
    expect($this->client->hasAllPermissions(['workspace.read'], $this->workspace->id))->toBeFalse();
});

it('keeps member submission and comments while requiring admin decisions', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->actingAs($member)->post(route('reviews.act', $this->post), ['action' => 'submit', 'revision' => $this->revision, 'stage' => 'internal'])->assertRedirect();
    $this->post(route('reviews.act', $this->post), ['action' => 'comment', 'revision' => $this->revision, 'stage' => 'internal', 'note' => 'Ready for your review'])->assertRedirect();
    $this->postJson(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'internal'])->assertForbidden();
});

it('makes approval retries idempotent and rejects an unrelated target', function () {
    stagedApproveInternally($this);
    $this->reviews->act($this->post, $this->owner, 'approve', $this->revision, null, 'reviews');
    expect(PostWorkflowEvent::query()->where('post_id', $this->post->id)->where('action', 'approve')->count())->toBe(1);
    $foreignTarget = PostTarget::factory()->create();
    $this->postJson(route('reviews.act', $this->post), ['action' => 'approve', 'revision' => $this->revision, 'stage' => 'internal', 'target_id' => $foreignTarget->id])->assertNotFound();
});

it('does not dispatch or publish a queued target while client review is pending', function () {
    Queue::fake();
    stagedApproveInternally($this);
    app(PublishDispatcher::class)->dispatchForPost($this->post->fresh());
    Queue::assertNotPushed(PublishPostTarget::class);
    app()->call([new PublishPostTarget($this->target->fresh()), 'handle']);
    Http::assertNothingSent();
});

it('requires authentication and rejects review changes for published posts', function () {
    $this->post->forceFill(['status' => PostStatus::Published])->save();
    $this->postJson(route('reviews.act', $this->post), ['action' => 'submit', 'revision' => $this->revision, 'stage' => 'internal'])->assertUnprocessable();
    auth()->logout();
    $this->getJson(route('reviews.index'))->assertUnauthorized();
});

it('denies old API keys after a member becomes a client', function () {
    [$user, $workspace, $token] = issuedKey();
    $workspace->members()->where('user_id', $user->id)->update(['role' => 'client']);
    $this->withToken($token)->getJson('/api/v1/connected-accounts')->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/posts', ['base_text' => 'Should be denied'])->assertForbidden();
});

it('denies old MCP grants after a member becomes a client', function () {
    $user = User::factory()->create();
    bindTokenToWorkspace($user, $this->workspace);
    WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $user->id)->update(['role' => 'client']);
    ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, ['base_text' => 'Should be denied', 'destination' => ['kind' => 'all']])->assertHasErrors();
});

it('allows ordinary workspace membership management to assign the restricted client role', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $membership = WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->patch(route('settings.workspace.members.update', $membership), ['role' => 'client'])->assertRedirect();
    expect($membership->fresh()->role)->toBe(WorkspaceRole::Client)
        ->and($membership->fresh()->permissions)->toBe(['workspace.review.client']);
});

it('uses the publishing resolver to show exactly which media belongs to each account section', function () {
    $media = PostMedia::factory()->create(['post_id' => $this->post->id, 'workspace_id' => $this->workspace->id, 'position' => 0]);
    $this->target->forceFill(['sections' => ['First', 'Second'], 'section_sources' => [0, 1], 'segment_breaks' => ['second']])->save();
    $this->target->placements()->create(['post_media_id' => $media->id, 'segment_ref' => 'second', 'position' => 0]);
    $this->get(route('reviews.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('posts.data.0.targets.0.media_by_section.1.0', $media->id));
});

it('rejects policy changes from a stale company tab and exposes the staged Airtable handoff', function () {
    $this->get(route('airtable.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('stagedReview', true));
    $other = Workspace::factory()->create(['owner_id' => $this->owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
    $this->owner->forceFill(['current_workspace_id' => $other->id])->save();
    $this->putJson(route('reviews.configure'), ['mode' => 'off', 'expected_workspace_id' => $this->workspace->id])->assertUnprocessable();
    expect($this->workspace->fresh()->getAttribute('review_mode'))->toBe('internal_client')
        ->and($other->fresh()->getAttribute('review_mode'))->toBe('off');
});
