<?php

use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use App\Models\CreatorAsset;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Services\Posts\PostReviewService;
use App\Support\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    [$this->actor, $this->workspace] = ownerActingIn();
    Storage::fake('local');
});

function libraryAssetPayload(CreatorAsset $asset, array $values = []): array
{
    return [...['expected_workspace_id' => test()->workspace->id, 'expected_revision' => $asset->revision, 'name' => $asset->name, 'folder' => null, 'tags' => [], 'starred' => false, 'archived' => false], ...$values];
}

function libraryMovePayload(ContentIdea $idea, array $values = []): array
{
    return [...['expected_workspace_id' => test()->workspace->id, 'expected_revision' => $idea->revision, 'status' => 'planned', 'before_id' => null], ...$values];
}

it('organizes and filters assets within the current company without changing their bytes', function () {
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    CreatorAsset::factory()->create(['name' => 'Foreign asset', 'folder' => 'Secret', 'tags' => ['private']]);
    $this->putJson(route('content.library.update', $asset), libraryAssetPayload($asset, ['folder' => 'Launch', 'tags' => ['summer'], 'starred' => true]))->assertOk()->assertJsonPath('asset.revision', 2);
    expect($asset->fresh()->path)->toBe($asset->path)->and($asset->fresh()->sha256)->toBe($asset->sha256);
    $this->get(route('content.library.index', ['folder' => 'Launch', 'tag' => 'summer', 'starred' => 1]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('assets.data', 1)->where('assets.data.0.id', $asset->id)->where('folders', ['Launch'])->where('tags', ['summer']));
    $this->putJson(route('content.library.update', $asset), libraryAssetPayload($asset))->assertConflict();
});

it('archives assets reversibly while keeping immutable content available to saved designs', function () {
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    Storage::disk('local')->put($asset->path, 'stored bytes');
    $this->putJson(route('content.library.update', $asset), libraryAssetPayload($asset, ['archived' => true]))->assertOk();
    $this->getJson(route('creator.assets.index'))->assertJsonCount(0, 'assets');
    $this->get(route('creator.assets.content', $asset))->assertOk();
    $this->get(route('content.library.index', ['archived' => 1]))->assertInertia(fn (AssertableInertia $page) => $page->has('assets.data', 1));
    $this->putJson(route('content.library.update', $asset), libraryAssetPayload($asset->fresh()))->assertOk();
    $this->getJson(route('creator.assets.index'))->assertJsonCount(1, 'assets');
});

it('restores history into new references and rejects repeated or stale restoration', function () {
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    Storage::disk('local')->put($asset->path, 'fixture');
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $snapshot = $project->document;
    $first = $this->postJson(route('content.library.restore', $asset), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertCreated()->assertJsonPath('asset.version', 2)->assertJsonPath('asset.parent_asset_id', $asset->id);
    $copy = CreatorAsset::query()->findOrFail($first->json('asset.id'));
    expect($copy->id)->not->toBe($asset->id)->and($copy->path)->toBe($asset->path)->and($copy->sha256)->toBe($asset->sha256)->and($project->fresh()->document)->toBe($snapshot);
    $this->postJson(route('content.library.restore', $asset), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertConflict();
    $this->postJson(route('content.library.restore', $copy), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertCreated()->assertJsonPath('asset.version', 3)->assertJsonPath('asset.root_asset_id', $asset->id);
    $this->getJson(route('content.library.versions', $asset))->assertOk()->assertJsonCount(3, 'assets')->assertJsonPath('assets.0.version', 3);
});

it('uploads a new version without modifying or replacing the previous image', function () {
    Storage::fake(FileStorage::diskName());
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $response = $this->post(route('content.library.upload-version', $asset), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1, 'file' => UploadedFile::fake()->image('new.png', 40, 40)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('asset.version', 2);
    $version = CreatorAsset::query()->findOrFail($response->json('asset.id'));
    expect($version->path)->not->toBe($asset->path)->and($asset->fresh()->path)->toBe($asset->path)->and($asset->fresh()->sha256)->toBe($asset->sha256);
});

it('blocks foreign asset reads writes restoration and switched-workspace submissions', function () {
    $foreign = CreatorAsset::factory()->create();
    $local = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->getJson(route('content.library.versions', $foreign))->assertNotFound();
    $this->putJson(route('content.library.update', $foreign), libraryAssetPayload($foreign))->assertNotFound();
    $this->postJson(route('content.library.restore', $foreign), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertNotFound();
    $this->putJson(route('content.library.update', $local), libraryAssetPayload($local, ['expected_workspace_id' => $foreign->workspace_id]))->assertUnprocessable();
});

it('moves and reorders ideas with optimistic concurrency and preserves converted drafts', function () {
    $first = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'planned', 'position' => 0]);
    $second = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'planned', 'position' => 1]);
    $this->postJson(route('content.ideas.move', $second), libraryMovePayload($second, ['before_id' => $first->id]))->assertOk();
    expect($second->fresh()->position)->toBe(0)->and($first->fresh()->position)->toBe(1);
    $this->postJson(route('content.ideas.move', $second), libraryMovePayload($second))->assertConflict();
    $this->postJson(route('content.ideas.move', $first), libraryMovePayload($first->fresh(), ['status' => 'drafted']))->assertUnprocessable();
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $converted = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'drafted', 'draft_post_id' => $post->id]);
    $this->postJson(route('content.ideas.move', $converted), libraryMovePayload($converted, ['status' => 'archived']))->assertOk();
    $this->postJson(route('content.ideas.move', $converted), libraryMovePayload($converted->fresh(), ['status' => 'inbox']))->assertUnprocessable();
    $this->postJson(route('content.ideas.move', $converted), libraryMovePayload($converted->fresh(), ['status' => 'drafted']))->assertOk();
    expect($converted->fresh()->draft_post_id)->toBe($post->id);
});

it('rejects foreign and moved Kanban anchors without partial reorder', function () {
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'planned']);
    $foreign = ContentIdea::factory()->create();
    $otherLane = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'inbox']);
    $this->postJson(route('content.ideas.move', $idea), libraryMovePayload($idea, ['before_id' => $foreign->id]))->assertUnprocessable();
    $this->postJson(route('content.ideas.move', $idea), libraryMovePayload($idea, ['before_id' => $otherLane->id]))->assertConflict();
    expect($idea->fresh()->revision)->toBe(1);
    $this->get(route('content.ideas.board'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('content/board')->has('ideas', 2));
});

it('creates template drafts with scoped destination assignments and independent ordered media copies', function () {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $assets = CreatorAsset::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    foreach ($assets as $asset) {
        Storage::disk('local')->put($asset->path, 'fixture');
    }
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'caption' => 'Launch', 'destination' => ['kind' => 'accounts', 'ids' => [$account->id]], 'media_asset_ids' => $assets->pluck('id')->reverse()->values()->all()]);
    $response = $this->postJson(route('content.templates.draft', $template), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertCreated();
    $post = Post::query()->findOrFail($response->json('post_id'));
    expect($post->status)->toBe(PostStatus::Draft)->and($post->scheduled_at)->toBeNull()->and($post->targets()->first()->connected_account_id)->toBe($account->id)->and($post->media()->count())->toBe(2)->and(app(PostReviewService::class)->canPublish($post))->toBeFalse();
    expect($post->media()->orderBy('position')->first()->path)->not->toBe($assets->last()->path);
    expect($post->targets()->first()->placements()->count())->toBe(2);
    $post->media()->first()->delete();
    Storage::disk('local')->assertExists($assets->first()->path);
    Storage::disk('local')->assertExists($assets->last()->path);
    Queue::assertNothingPushed();
});

it('applies explicit workspace defaults when converting ideas and remains idempotent', function () {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->workspace->update(['default_connected_account_id' => $account->id]);
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'destination' => ['kind' => 'default']]);
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'template_id' => $template->id]);
    $response = $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertOk();
    $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertOk()->assertJsonPath('post_id', $response->json('post_id'));
    expect(Post::query()->findOrFail($response->json('post_id'))->targets()->first()->connected_account_id)->toBe($account->id);
});

it('revalidates template references and rolls back a failed media copy without orphan drafts', function () {
    $foreign = ConnectedAccount::factory()->create();
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'destination' => ['kind' => 'accounts', 'ids' => [$foreign->id]]]);
    $payload = ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1];
    $this->postJson(route('content.templates.draft', $template), $payload)->assertUnprocessable();
    $foreignAsset = CreatorAsset::factory()->create();
    $template->update(['destination' => ['kind' => 'none'], 'media_asset_ids' => [$foreignAsset->id]]);
    $this->postJson(route('content.templates.draft', $template), $payload)->assertUnprocessable();
    $first = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $missing = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    Storage::disk('local')->put($first->path, 'fixture');
    $template->update(['media_asset_ids' => [$first->id, $missing->id]]);
    $this->postJson(route('content.templates.draft', $template), $payload)->assertServerError();
    expect(Post::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles('media'))->toBe([]);
});

it('validates saved template assignments against workspace scope and revision', function () {
    $foreign = ConnectedAccount::factory()->create();
    $foreignAsset = CreatorAsset::factory()->create();
    $payload = ['expected_workspace_id' => $this->workspace->id, 'name' => 'Assigned', 'brief' => '', 'hashtags' => [], 'destination' => ['kind' => 'accounts', 'ids' => [$foreign->id]], 'media_asset_ids' => [$foreignAsset->id]];
    $this->postJson(route('content.templates.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors(['destination.ids.0', 'media_asset_ids.0']);
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'revision' => 2]);
    $this->postJson(route('content.templates.draft', $template), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertConflict();
    expect(Post::query()->count())->toBe(0);
});

it('requires available defaults and enabled accounts and rejects archived template drafts', function () {
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'destination' => ['kind' => 'default']]);
    $payload = ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1];
    $this->postJson(route('content.templates.draft', $template), $payload)->assertUnprocessable();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'disabled_at' => now()]);
    $template->update(['destination' => ['kind' => 'accounts', 'ids' => [$account->id]]]);
    $this->postJson(route('content.templates.draft', $template), $payload)->assertUnprocessable();
    $template->update(['destination' => ['kind' => 'none'], 'archived_at' => now()]);
    $this->postJson(route('content.templates.draft', $template), $payload)->assertUnprocessable();
    expect(Post::query()->count())->toBe(0);
});

it('requires authentication for the library board and mutation routes', function () {
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    auth()->logout();
    $this->getJson(route('content.library.index'))->assertUnauthorized();
    $this->getJson(route('content.ideas.board'))->assertUnauthorized();
    $this->putJson(route('content.library.update', $asset), libraryAssetPayload($asset))->assertUnauthorized();
});

it('refuses to restore a missing original instead of creating a broken asset reference', function () {
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->postJson(route('content.library.restore', $asset), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 1])->assertUnprocessable();
    expect(CreatorAsset::query()->count())->toBe(1)->and($asset->fresh()->revision)->toBe(1);
});
