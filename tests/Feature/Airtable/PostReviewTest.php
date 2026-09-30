<?php

use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Models\AirtableIntegration;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostWorkflowEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PostReviewService;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\PublishDispatcher;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->actor, $this->workspace] = ownerActingIn();
    $this->post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'author_id' => $this->actor->id, 'segments' => ['Review me'], 'base_text' => 'Review me']);
    $this->reviews = app(PostReviewService::class);
});

it('keeps existing dashboard-only drafts publishable without opting into approvals', function () {
    expect($this->reviews->status($this->post))->toBe('not_required')->and($this->reviews->canPublish($this->post))->toBeTrue();
    $this->get(route('airtable.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('airtable/index')->where('integration', null)->has('posts', 1));
});

it('uses authenticated reviewer identity for both dashboard and Airtable entrypoints', function () {
    $revision = $this->reviews->revision($this->post);
    $this->post(route('posts.review', $this->post), ['action' => 'submit', 'revision' => $revision, 'source' => 'airtable'])->assertRedirect();
    $this->post(route('posts.review', $this->post), ['action' => 'approve', 'revision' => $revision, 'source' => 'airtable', 'note' => 'Reviewed'])->assertRedirect();
    expect($this->reviews->status($this->post->fresh()))->toBe('approved')
        ->and(PostWorkflowEvent::query()->where('action', 'approve')->value('actor_id'))->toBe($this->actor->id);
    $this->post(route('posts.review', $this->post), ['action' => 'approve', 'revision' => $revision, 'source' => 'airtable', 'note' => 'Reviewed'])->assertRedirect();
    expect(PostWorkflowEvent::query()->where('action', 'approve')->count())->toBe(1);
});

it('requires admin review permission while allowing members to submit', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->actingAs($member);
    $revision = $this->reviews->revision($this->post);
    $this->post(route('posts.review', $this->post), ['action' => 'submit', 'revision' => $revision, 'source' => 'dashboard'])->assertRedirect();
    $this->post(route('posts.review', $this->post), ['action' => 'approve', 'revision' => $revision, 'source' => 'dashboard'])->assertForbidden();
    $this->put(route('airtable.update'), [])->assertForbidden();
});

it('rejects cross-workspace review requests and selections', function () {
    $foreign = Post::factory()->create();
    $this->post(route('posts.review', $foreign), ['action' => 'submit', 'revision' => $this->reviews->revision($foreign), 'source' => 'dashboard'])->assertNotFound();
    $this->get(route('airtable.index', ['post' => $foreign->id]))->assertNotFound();
});

it('rejects stale approval revisions and invalidates an approval after text changes', function () {
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'dashboard');
    $this->post->forceFill(['segments' => ['Changed'], 'base_text' => 'Changed'])->save();
    expect($this->reviews->status($this->post->fresh()))->toBe('stale')->and($this->reviews->canPublish($this->post->fresh()))->toBeFalse();
    $this->post(route('posts.review', $this->post), ['action' => 'approve', 'revision' => $revision, 'source' => 'airtable'])->assertConflict();
});

it('invalidates approvals when a destination or target override changes', function () {
    $target = PostTarget::factory()->for($this->post)->create();
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'dashboard');
    $target->forceFill(['sections' => ['Unreviewed override']])->save();
    expect($this->reviews->canPublish($this->post->fresh()))->toBeFalse();
});

it('invalidates approval when thread-to-media routing changes', function (string $field, array $value) {
    $target = PostTarget::factory()->for($this->post)->create();
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'dashboard');

    $target->forceFill([$field => $value])->save();

    expect($this->reviews->canPublish($this->post->fresh()))->toBeFalse();
})->with([
    'authored thread breaks' => ['segment_breaks', ['new-segment']],
    'published section sources' => ['section_sources', [1]],
]);

it('blocks all dispatcher entrypoints before an unapproved revision reaches a connector', function () {
    Queue::fake();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Review me']]);
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    expect(app(PublishPrecheck::class)->blockingTargets($this->post->fresh())[0]['issues'])->toContain('review_required');
    app(PublishDispatcher::class)->dispatchForPost($this->post->fresh());
    Queue::assertNotPushed(PublishPostTarget::class);
});

it('rechecks approval for a stale queued job before any provider request', function () {
    $target = PostTarget::factory()->for($this->post)->create();
    $this->reviews->act($this->post, $this->actor, 'submit', $this->reviews->revision($this->post), null, 'airtable');
    app()->call([new PublishPostTarget($target), 'handle']);
    expect($target->fresh()->error_message)->toContain('needs approval');
    Http::assertNothingSent();
});

it('persists scoped configuration without exposing credentials', function () {
    config(['airtable.tokens' => [$this->workspace->id => 'should-never-leak']]);
    $this->put(route('airtable.update'), ['enabled' => true, 'base_id' => 'appScoped', 'table_id' => 'tblPosts', 'interface_url' => 'https://airtable.com/appScoped', 'sync_interval_minutes' => 0])->assertRedirect();
    expect(AirtableIntegration::query()->first()->workspace_id)->toBe($this->workspace->id);
    $response = $this->get(route('airtable.index'));
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('integration.token_configured', true));
    expect($response->getContent())->not->toContain('should-never-leak');
});

it('rejects external interface URLs and excessive scheduled polling', function () {
    $data = ['enabled' => true, 'base_id' => 'appScoped', 'table_id' => 'tblPosts', 'interface_url' => 'https://evil.example/airtable', 'sync_interval_minutes' => 0];
    $this->put(route('airtable.update'), $data)->assertUnprocessable();
    $this->putJson(route('airtable.update'), [...$data, 'interface_url' => null, 'sync_interval_minutes' => 1])->assertUnprocessable();
    $this->putJson(route('airtable.update'), [...$data, 'interface_url' => null, 'post_name_field' => 'Text'])->assertUnprocessable();
});

it('retains required review when a reviewed post is copied into a new draft', function () {
    $this->reviews->act($this->post, $this->actor, 'submit', $this->reviews->revision($this->post), null, 'dashboard');
    $this->post->refresh()->forceFill(['status' => PostStatus::Failed])->save();
    $copy = app(PostDuplicator::class)->duplicate($this->post);
    expect($this->reviews->status($copy))->toBe('pending')->and($this->reviews->canPublish($copy))->toBeFalse();
});

it('lets a failed approval-blocked post be reviewed before an explicit retry', function () {
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    $this->post->refresh()->forceFill(['status' => PostStatus::Failed])->save();
    $approved = $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'airtable');
    expect($this->reviews->canPublish($approved))->toBeTrue()->and($approved->status)->toBe(PostStatus::Failed);
});

it('rechecks the frozen content snapshot after token work and blocks newly attached media', function () {
    Notification::fake();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $target = PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id]);
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'dashboard');
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->andReturnUsing(function () {
        PostMedia::factory()->create(['post_id' => $this->post->id, 'workspace_id' => $this->workspace->id]);

        return ['access_token' => 'fake-token'];
    });
    app()->instance(TokenManager::class, $tokens);
    bindConnector(fn () => throw new RuntimeException('An unapproved snapshot must never reach the connector.'));

    app()->call([new PublishPostTarget($target), 'handle']);

    expect($target->fresh()->error_kind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

it('rechecks approval before retrying a publish with refreshed credentials', function () {
    Notification::fake();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $target = PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id]);
    $revision = $this->reviews->revision($this->post);
    $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
    $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'dashboard');
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->withArgs(fn ($account, $force = false) => ! $force)->andReturn(['access_token' => 'fake-token']);
    $tokens->shouldReceive('fresh')->once()->withArgs(fn ($account, $force) => $force)->andReturnUsing(function () use ($target) {
        $target->forceFill(['sections' => ['Unreviewed text']])->save();

        return ['access_token' => 'refreshed-fake-token'];
    });
    app()->instance(TokenManager::class, $tokens);
    $calls = 0;
    bindConnector(function () use (&$calls) {
        $calls++;

        return PublishResult::failure(ErrorKind::AuthExpired, 'Refresh test');
    });

    app()->call([new PublishPostTarget($target), 'handle']);

    expect($calls)->toBe(1)->and($target->fresh()->error_kind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});
