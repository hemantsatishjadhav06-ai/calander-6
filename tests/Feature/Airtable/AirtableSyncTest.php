<?php

use App\Enums\PostStatus;
use App\Models\AccountSet;
use App\Models\AirtableIntegration;
use App\Models\AirtablePostLink;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostWorkflowEvent;
use App\Services\Airtable\AirtableSyncService;
use App\Services\Posts\PostReviewService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    [$this->actor, $this->workspace] = ownerActingIn();
    $this->integration = AirtableIntegration::create([
        'workspace_id' => $this->workspace->id,
        'configured_by_id' => $this->actor->id,
        'enabled' => true, 'base_id' => 'appTest123', 'table_id' => 'tblTest123',
    ]);
    config(['airtable.tokens' => [$this->workspace->id => 'test-secret']]);
});

function airtableMockRecord(string $id, array $fields): array
{
    return ['id' => $id, 'fields' => $fields];
}

function airtableRunSync(): void
{
    test()->integration->forceFill(['cooldown_until' => null])->save();
    app(AirtableSyncService::class)->sync(test()->integration);
}

it('exports canonical drafts and never sends provider credentials or editable proposal cells', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'segments' => ['Hello'], 'base_text' => 'Hello']);
    Post::factory()->create();
    Http::fakeSequence()->push(['records' => []])->push(['records' => [['id' => 'recOne']]]);
    airtableRunSync();
    Http::assertSentCount(2);
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'PATCH'
        && $r['records'][0]['fields']['App Post ID'] === $post->id
        && ! isset($r['records'][0]['fields']['Draft text'])
        && ! str_contains(json_encode($r->data()), 'test-secret'));
    expect($this->integration->fresh()->last_synced_at)->not->toBeNull();
    expect(AirtablePostLink::count())->toBe(1);
});

it('imports a new Airtable row exactly once without selecting accounts or publishing', function () {
    $remote = airtableMockRecord('recNew', ['Draft text' => 'Airtable draft']);
    Http::fake(function (ClientRequest $request) use (&$remote) {
        if ($request->method() === 'PATCH') {
            $remote['fields'] = [...$remote['fields'], ...$request['records'][0]['fields']];
        }

        return Http::response(['records' => [$remote]]);
    });
    airtableRunSync();
    airtableRunSync();
    $post = Post::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->firstOrFail();
    expect(Post::count())->toBe(1)->and($post->status)->toBe(PostStatus::Draft)
        ->and($post->targets()->count())->toBe(0)
        ->and($post->getAttribute('review_required'))->toBeTruthy()
        ->and(app(PostReviewService::class)->status($post))->toBe('pending')
        ->and(PostWorkflowEvent::count())->toBe(1);
    Http::assertSentCount(3);
});

it('applies a revision-matched text proposal and resets approval', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'segments' => ['Before'], 'base_text' => 'Before']);
    $reviews = app(PostReviewService::class);
    $revision = $reviews->revision($post);
    $post->forceFill(['review_required' => true, 'review_status' => 'approved', 'review_revision' => $revision])->save();
    AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recOne']);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['App Post ID' => $post->id, 'Draft text' => 'After', 'Draft revision' => $revision])]])->push(['records' => [['id' => 'recOne']]]);
    airtableRunSync();
    expect($post->fresh()->base_text)->toBe('After')->and($reviews->status($post->fresh()))->toBe('pending');
});

it('retains both sides of a stale proposal without overwriting dashboard content', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'segments' => ['Dashboard'], 'base_text' => 'Dashboard']);
    $link = AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recOne']);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['Draft text' => 'Proposal', 'Draft revision' => str_repeat('0', 64)])]])->push(['records' => [['id' => 'recOne']]]);
    airtableRunSync();
    expect($post->fresh()->base_text)->toBe('Dashboard')->and($link->fresh()->conflict['text'])->toBe('Proposal');
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'PATCH' && ! array_key_exists('Draft text', $r['records'][0]['fields']));
});

it('does not trust a supplied foreign app post ID or editable approval cell', function () {
    $foreign = Post::factory()->create();
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recBad', ['App Post ID' => $foreign->id, 'Draft text' => 'Hacked', 'Review status' => 'approved'])]]);
    airtableRunSync();
    expect(AirtablePostLink::count())->toBe(0)->and($foreign->fresh()->base_text)->not->toBe('Hacked');
});

it('stops on rate limits and persists a safe error and cooldown', function () {
    Http::fakeSequence()->push(['error' => 'secret provider body'], 429);
    airtableRunSync();
    expect($this->integration->fresh()->last_error)->toContain('paused for one hour')
        ->and($this->integration->fresh()->last_error)->not->toContain('secret')
        ->and($this->integration->fresh()->cooldown_until->isFuture())->toBeTrue();
    app(AirtableSyncService::class)->sync($this->integration);
    Http::assertSentCount(1);
});

it('enforces the local monthly call budget without network requests', function () {
    $this->integration->forceFill(['quota_month' => now()->utc()->format('Y-m'), 'api_calls' => 800])->save();
    airtableRunSync();
    Http::assertNothingSent();
    expect($this->integration->fresh()->last_error)->toContain('budget is exhausted');
});

it('does not overlap a live database lease or run while disabled', function () {
    $this->integration->forceFill(['lease_until' => now()->addMinutes(10)])->save();
    airtableRunSync();
    $this->integration->forceFill(['lease_until' => null, 'enabled' => false])->save();
    airtableRunSync();
    Http::assertNothingSent();
});

it('does not propagate Airtable deletion into the dashboard', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $link = AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recGone']);
    Http::fakeSequence()->push(['records' => []]);
    airtableRunSync();
    expect($post->fresh())->not->toBeNull()->and($link->fresh()->sync_error)->toContain('preserved');
    Http::assertSentCount(1);
});

it('refuses a partial pull when the record limit is exceeded', function () {
    config(['airtable.max_records' => 1]);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['Draft text' => 'No partial import'])], 'offset' => 'next']);
    airtableRunSync();
    expect(Post::count())->toBe(0)->and($this->integration->fresh()->last_error)->toContain('No partial pull');
});

it('stops before changes if Airtable has duplicate app post IDs', function () {
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['App Post ID' => 'same']), airtableMockRecord('recTwo', ['App Post ID' => 'same'])]]);
    airtableRunSync();
    expect($this->integration->fresh()->last_error)->toContain('Duplicate App Post IDs');
    Http::assertSentCount(1);
});

it('preserves account set identity and disabled target overrides on text-only imports', function () {
    $set = AccountSet::factory()->create(['workspace_id' => $this->workspace->id]);
    $account = ConnectedAccount::factory()->disabled()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'account_set_id' => $set->id, 'segments' => ['Before']]);
    $override = ['segments' => ['Account-specific text'], 'media_ids' => []];
    $target = PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id, 'content_override' => $override]);
    $revision = app(PostReviewService::class)->revision($post);
    AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recOne']);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['Draft text' => 'After', 'Draft revision' => $revision])]])->push(['records' => [['id' => 'recOne']]]);

    airtableRunSync();

    expect($post->fresh()->base_text)->toBe('After')
        ->and($post->fresh()->account_set_id)->toBe($set->id)
        ->and($target->fresh()->content_override)->toBe($override)
        ->and($post->targets()->count())->toBe(1);
});

it('does not export new rows beyond its safe record capacity', function () {
    config(['airtable.max_records' => 2]);
    Post::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recExisting', [])]])->push(['records' => [['id' => 'recNew']]]);

    airtableRunSync();

    Http::assertSentCount(2);
    expect(AirtablePostLink::whereNotNull('record_id')->count())->toBe(1)
        ->and(AirtablePostLink::count())->toBe(2)
        ->and($this->integration->fresh()->last_error)->toContain('safety limit');
});

it('recovers an ambiguous upsert from the persisted app mapping without duplicating a post', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    Http::fakeSequence()->push(['records' => []])->push(['records' => []])
        ->push(['records' => [airtableMockRecord('recRecovered', ['App Post ID' => $post->id])]])
        ->push(['records' => [['id' => 'recRecovered']]]);
    airtableRunSync();
    expect(AirtablePostLink::first()->record_id)->toBeNull();

    airtableRunSync();

    expect(AirtablePostLink::count())->toBe(1)
        ->and(AirtablePostLink::first()->record_id)->toBe('recRecovered')
        ->and(Post::count())->toBe(1);
    Http::assertSentCount(4);
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PATCH' && ($request['records'][0]['id'] ?? null) === 'recRecovered' && ! isset($request['performUpsert']));
});

it('corrects a hand-edited approval cell without authorizing publication', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $reviews = app(PostReviewService::class);
    $reviews->act($post, $this->actor, 'submit', $reviews->revision($post), null, 'dashboard');
    AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recOne']);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['App Post ID' => $post->id, 'Review status' => 'approved'])]])->push(['records' => [['id' => 'recOne']]]);

    airtableRunSync();

    expect($reviews->canPublish($post->fresh()))->toBeFalse();
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PATCH' && $request['records'][0]['fields']['Review status'] === 'pending');
});

it('rejects stale conflict decisions and keeps the proposal for a current decision', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'segments' => ['Dashboard']]);
    $revision = app(PostReviewService::class)->revision($post);
    $conflict = ['text' => 'Proposal', 'revision' => str_repeat('0', 64), 'hash' => str_repeat('1', 64)];
    $link = AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recOne', 'conflict' => $conflict]);

    $this->post(route('airtable.resolve', $post), ['resolution' => 'airtable', 'revision' => $revision, 'conflict_hash' => str_repeat('2', 64)])->assertConflict();
    expect($link->fresh()->conflict)->toBe($conflict);
    $this->post(route('airtable.resolve', $post), ['resolution' => 'dashboard', 'revision' => $revision, 'conflict_hash' => $conflict['hash']])->assertRedirect();
    expect($link->fresh()->conflict)->toBeNull()->and($post->fresh()->segments)->toBe(['Dashboard']);
    Http::assertNothingSent();
});

it('seeds optional names only for new exported rows and preserves manual names', function () {
    $this->integration->forceFill(['post_name_field' => 'Post name'])->save();
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'segments' => ['A clear title'], 'base_text' => 'A clear title']);
    Http::fakeSequence()->push(['records' => []])->push(['records' => [['id' => 'recNamed']]])
        ->push(['records' => [airtableMockRecord('recNamed', ['App Post ID' => $post->id, 'Post name' => 'My manually chosen title'])]])
        ->push(['records' => [['id' => 'recNamed']]]);

    airtableRunSync();
    airtableRunSync();

    Http::assertSent(fn (ClientRequest $request) => isset($request['performUpsert']) && $request['records'][0]['fields']['Post name'] === 'A clear title');
    Http::assertSent(fn (ClientRequest $request) => ($request['records'][0]['id'] ?? null) === 'recNamed' && ! array_key_exists('Post name', $request['records'][0]['fields']));
});

it('corrects a forged canonical approval status on the next sync', function () {
    $remote = airtableMockRecord('recNew', ['Draft text' => 'Draft']);
    Http::fake(function (ClientRequest $request) use (&$remote) {
        if ($request->method() === 'PATCH') {
            $remote['fields'] = [...$remote['fields'], ...$request['records'][0]['fields']];
        }

        return Http::response(['records' => [$remote]]);
    });
    airtableRunSync();
    $remote['fields']['Review status'] = 'approved';
    airtableRunSync();
    expect($remote['fields']['Review status'])->toBe('pending');
});

it('keeps a scheduled post unchanged when an Airtable proposal arrives', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => PostStatus::Scheduled, 'segments' => ['Scheduled'], 'base_text' => 'Scheduled']);
    $link = AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id, 'record_id' => 'recOne']);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recOne', ['Draft text' => 'Changed', 'Draft revision' => app(PostReviewService::class)->revision($post)])]])->push(['records' => [['id' => 'recOne']]]);
    airtableRunSync();
    expect($post->fresh()->base_text)->toBe('Scheduled')->and($link->fresh()->conflict)->not->toBeNull();
});

it('recovers an outbound row after a previously ambiguous upsert without creating a duplicate', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $link = AirtablePostLink::create(['integration_id' => $this->integration->id, 'post_id' => $post->id, 'app_post_id' => $post->id]);
    Http::fakeSequence()->push(['records' => [airtableMockRecord('recRecovered', ['App Post ID' => $post->id])]])->push(['records' => [['id' => 'recRecovered']]]);
    airtableRunSync();
    expect($link->fresh()->record_id)->toBe('recRecovered');
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PATCH' && $request['records'][0]['id'] === 'recRecovered' && ! isset($request['performUpsert']));
});
