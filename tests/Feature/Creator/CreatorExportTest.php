<?php

declare(strict_types=1);

use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\CreatorExport;
use App\Models\CreatorExportBatch;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Creator\CreatorDocument;
use App\Services\Creator\CreatorExportFreshness;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PostReviewService;
use App\Services\Publishing\PublishDispatcher;
use App\Services\Publishing\TokenManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** @return array<string, mixed> */
function creatorExportDocument(int $slideCount = 2): array
{
    return [
        'schema_version' => 1,
        'canvas' => ['width' => 64, 'height' => 64],
        'slides' => array_map(fn (int $index): array => [
            'id' => 'slide-'.$index,
            'name' => 'Slide '.$index,
            'background_color' => '#ffffff',
            'layers' => [],
        ], range(1, $slideCount)),
    ];
}

/** @return array<string, mixed> */
function creatorExportPayload(Post $post, CreatorProject $project): array
{
    return [
        'project_id' => $project->id,
        'project_revision' => $project->revision,
        'expected_post_revision' => app(PostReviewService::class)->revision($post->fresh()),
        'idempotency_key' => (string) Str::uuid(),
        'slides' => array_map(fn (array $slide): array => [
            'slide_id' => $slide['id'],
            'file' => UploadedFile::fake()->image($slide['id'].'.png', 64, 64),
            'alt_text' => $slide['name'],
        ], $project->document['slides']),
    ];
}

/** @param array<string, mixed> $payload */
function submitCreatorExport(Post $post, array $payload): TestResponse
{
    return test()->post(route('posts.creator-exports.store', $post), $payload, ['Accept' => 'application/json']);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['filesystems.default' => 'local', 'filesystems.public_images' => 'public']);
    Storage::fake('local');
    Storage::fake('public');
    [$this->actor, $this->workspace] = ownerActingIn();
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->actor->id,
        'base_text' => 'Creator carousel',
        'segments' => ['Creator carousel'],
    ]);
    $this->project = CreatorProject::factory()->create([
        'workspace_id' => $this->workspace->id,
        'document' => creatorExportDocument(),
    ]);
    $this->reviews = app(PostReviewService::class);
});

it('requires authentication before exporting or reading a post revision', function (): void {
    $payload = creatorExportPayload($this->post, $this->project);
    Auth::logout();

    submitCreatorExport($this->post, $payload)->assertUnauthorized();
    $this->getJson(route('posts.creator-context.show', $this->post))->assertUnauthorized();
    expect(CreatorExport::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects exports to foreign posts or from foreign projects', function (): void {
    $foreignPost = Post::factory()->create();
    $foreignProject = CreatorProject::factory()->create();

    submitCreatorExport($foreignPost, creatorExportPayload($foreignPost, $this->project))->assertNotFound();
    submitCreatorExport($this->post, creatorExportPayload($this->post, $foreignProject))->assertNotFound();
    $this->getJson(route('posts.creator-context.show', $foreignPost))->assertNotFound();

    expect(CreatorExport::withoutGlobalScopes()->count())->toBe(0)
        ->and(PostMedia::withoutGlobalScopes()->whereIn('post_id', [$this->post->id, $foreignPost->id])->count())->toBe(0);
});

it('fails closed for export requests without a workspace', function (): void {
    $payload = creatorExportPayload($this->post, $this->project);
    $user = User::factory()->create(['current_workspace_id' => null]);
    Context::flush();
    $this->actingAs($user);

    submitCreatorExport($this->post, $payload)->assertNotFound();
    expect(CreatorExport::withoutGlobalScopes()->count())->toBe(0);
});

it('exports every slide in order while preserving unrelated post media', function (): void {
    $unrelated = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id,
        'post_id' => $this->post->id,
        'disk' => 'public',
        'path' => 'media/unrelated.png',
        'position' => 0,
    ]);
    Storage::disk('public')->put($unrelated->path, 'existing image');
    $oldRevision = $this->reviews->revision($this->post);

    $response = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))
        ->assertCreated()->assertJsonCount(2, 'media')
        ->assertJsonPath('media.0.alt_text', 'Slide 1')
        ->assertJsonPath('media.1.alt_text', 'Slide 2');
    $mediaIds = array_column($response->json('media'), 'id');
    $ordered = PostMedia::query()->where('post_id', $this->post->id)->orderBy('position')->get();
    expect($ordered->pluck('id')->all())->toBe([$unrelated->id, ...$mediaIds]);
    foreach ($mediaIds as $index => $mediaId) {
        $media = PostMedia::query()->findOrFail($mediaId);
        $export = CreatorExport::query()->where('post_media_id', $mediaId)->firstOrFail();
        expect($media->mime)->toBe('image/png')
            ->and($media->workspace_id)->toBe($this->workspace->id)
            ->and($export->project_id)->toBe($this->project->id)
            ->and($export->project_revision)->toBe(1)
            ->and($export->slide_id)->toBe('slide-'.($index + 1));
        Storage::disk($media->disk)->assertExists($media->path);
    }
    $this->assertModelExists($unrelated);
    Storage::disk('public')->assertExists($unrelated->path);
    expect($response->json('revision'))->not->toBe($oldRevision)
        ->and($response->json('revision'))->toBe($this->reviews->revision($this->post->fresh()))
        ->and(CreatorExportBatch::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('returns the current post revision for the Creator handoff', function (): void {
    $this->getJson(route('posts.creator-context.show', $this->post))->assertOk()
        ->assertJsonPath('post.id', $this->post->id)
        ->assertJsonPath('revision', $this->reviews->revision($this->post))
        ->assertJsonPath('review_status', 'not_required');
});

it('requires the complete ordered slide set', function (string $case): void {
    $payload = creatorExportPayload($this->post, $this->project);
    if ($case === 'missing') {
        array_pop($payload['slides']);
    } elseif ($case === 'reordered') {
        $payload['slides'] = array_reverse($payload['slides']);
    } elseif ($case === 'duplicate') {
        $payload['slides'][1]['slide_id'] = $payload['slides'][0]['slide_id'];
    } else {
        $payload['slides'][1]['slide_id'] = 'unknown-slide';
    }

    submitCreatorExport($this->post, $payload)->assertUnprocessable();
    expect(CreatorExport::query()->count())->toBe(0)
        ->and(PostMedia::query()->where('post_id', $this->post->id)->count())->toBe(0);
})->with(['missing', 'reordered', 'duplicate', 'unknown']);

it('rejects exports of stale project or post revisions without attaching media', function (string $case): void {
    $payload = creatorExportPayload($this->post, $this->project);
    if ($case === 'project') {
        $this->project->forceFill(['revision' => 2])->save();
    } else {
        $this->post->forceFill(['base_text' => 'Changed text', 'segments' => ['Changed text']])->save();
    }

    submitCreatorExport($this->post, $payload)->assertConflict();
    expect(PostMedia::query()->where('post_id', $this->post->id)->count())->toBe(0)
        ->and(CreatorExportBatch::query()->count())->toBe(0);
})->with(['project', 'post']);

it('returns the same media for an exact idempotent retry despite the changed post revision', function (): void {
    $payload = creatorExportPayload($this->post, $this->project);
    $first = submitCreatorExport($this->post, $payload)->assertCreated();
    $ids = array_column($first->json('media'), 'id');

    $second = submitCreatorExport($this->post, $payload)->assertSuccessful();

    expect(array_column($second->json('media'), 'id'))->toBe($ids)
        ->and(PostMedia::query()->where('post_id', $this->post->id)->count())->toBe(2)
        ->and(CreatorExport::query()->count())->toBe(2)
        ->and(CreatorExportBatch::query()->count())->toBe(1);
});

it('rejects reusing an idempotency key with different slide data', function (): void {
    $payload = creatorExportPayload($this->post, $this->project);
    submitCreatorExport($this->post, $payload)->assertCreated();
    $payload['slides'][0]['alt_text'] = 'Different payload';

    submitCreatorExport($this->post, $payload)->assertConflict();

    expect(PostMedia::query()->where('post_id', $this->post->id)->count())->toBe(2)
        ->and(CreatorExportBatch::query()->count())->toBe(1);
});

it('replaces only the specified old Creator exports and retains unrelated attachments', function (): void {
    $unrelated = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id, 'post_id' => $this->post->id,
        'disk' => 'public', 'path' => 'media/keep.png', 'position' => 0,
    ]);
    Storage::disk('public')->put($unrelated->path, 'keep me');
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $oldMedia = PostMedia::query()->whereIn('id', $oldIds)->get();
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['replace_media_ids'] = $oldIds;

    $second = submitCreatorExport($this->post, $payload)->assertCreated();
    $newIds = array_column($second->json('media'), 'id');

    expect(array_intersect($oldIds, $newIds))->toBe([])
        ->and(PostMedia::query()->where('post_id', $this->post->id)->orderBy('position')->pluck('id')->all())
        ->toBe([$unrelated->id, ...$newIds]);
    foreach ($oldMedia as $media) {
        $this->assertModelExists($media);
        expect($media->fresh()->post_id)->toBeNull();
        Storage::disk($media->disk)->assertExists($media->path);
    }
    $this->assertModelExists($unrelated);
    Storage::disk('public')->assertExists($unrelated->path);
});

it('does not let Creator replacements delete unrelated or foreign media', function (string $case): void {
    $otherPost = $case === 'foreign' ? Post::factory()->create() : $this->post;
    $media = PostMedia::factory()->create([
        'workspace_id' => $otherPost->workspace_id,
        'post_id' => $otherPost->id,
        'disk' => 'public',
        'path' => 'media/protected.png',
    ]);
    Storage::disk('public')->put($media->path, 'protected');
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['replace_media_ids'] = [$media->id];

    submitCreatorExport($this->post, $payload)->assertUnprocessable();
    $this->assertModelExists($media);
    Storage::disk('public')->assertExists($media->path);
    expect(CreatorExport::query()->count())->toBe(0);
})->with(['unrelated', 'foreign']);

it('rejects non-raster and disguised non-image export files atomically', function (): void {
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['slides'][1]['file'] = UploadedFile::fake()->create('slide.png', 2, 'text/html');

    submitCreatorExport($this->post, $payload)->assertUnprocessable();

    expect(PostMedia::query()->where('post_id', $this->post->id)->count())->toBe(0)
        ->and(CreatorExportBatch::query()->count())->toBe(0);
});

it('does not export into a post that has already been published', function (): void {
    $this->post->forceFill(['status' => PostStatus::Published])->save();

    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertUnprocessable();

    expect(PostMedia::query()->where('post_id', $this->post->id)->count())->toBe(0);
});

it('invalidates review and blocks publishing when an exported source design changes', function (bool $requiresReview): void {
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $revision = $this->reviews->revision($this->post->fresh());
    if ($requiresReview) {
        $this->reviews->act($this->post, $this->actor, 'submit', $revision, null, 'dashboard');
        $this->reviews->act($this->post, $this->actor, 'approve', $revision, null, 'dashboard');
    }
    expect($this->reviews->canPublish($this->post->fresh()))->toBeTrue();
    $document = $this->project->document;
    $document['slides'][0]['background_color'] = '#ff0000';

    $this->putJson(route('creator.projects.update', $this->project), [
        'name' => 'Changed source', 'document' => $document, 'expected_revision' => 1,
    ])->assertOk()->assertJsonPath('project.revision', 2);

    $current = $this->reviews->revision($this->post->fresh());
    expect($current)->not->toBe($revision)
        ->and(app(CreatorExportFreshness::class)->hasStaleExports($this->post->fresh()))->toBeTrue()
        ->and($this->reviews->canPublish($this->post->fresh()))->toBeFalse();
    $this->postJson(route('posts.review', $this->post), [
        'action' => 'submit', 'revision' => $current, 'source' => 'dashboard',
    ])->assertUnprocessable();
    $this->postJson(route('posts.review', $this->post), [
        'action' => 'approve', 'revision' => $current, 'source' => 'dashboard',
    ])->assertUnprocessable();

    $payload = creatorExportPayload($this->post, $this->project->fresh());
    $payload['replace_media_ids'] = array_column($first->json('media'), 'id');
    submitCreatorExport($this->post, $payload)->assertCreated();

    expect(app(CreatorExportFreshness::class)->hasStaleExports($this->post->fresh()))->toBeFalse();
    if ($requiresReview) {
        expect($this->reviews->canPublish($this->post->fresh()))->toBeFalse();
        $newRevision = $this->reviews->revision($this->post->fresh());
        $this->reviews->act($this->post, $this->actor, 'submit', $newRevision, null, 'dashboard');
        $this->reviews->act($this->post, $this->actor, 'approve', $newRevision, null, 'dashboard');
    }
    expect($this->reviews->canPublish($this->post->fresh()))->toBeTrue();
})->with(['reviewed post' => true, 'ordinary draft' => false]);

it('blocks the dispatcher before stale Creator media reaches a publishing job', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Creator carousel'],
    ]);
    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $this->project->forceFill(['revision' => 2])->save();
    Queue::fake();

    app(PublishDispatcher::class)->dispatchForPost($this->post->fresh());

    Queue::assertNotPushed(PublishPostTarget::class);
    Http::assertNothingSent();
});

it('blocks an already queued job for stale Creator media without making provider requests', function (): void {
    $target = PostTarget::factory()->for($this->post)->create();
    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $this->project->forceFill(['revision' => 2])->save();

    app()->call([new PublishPostTarget($target), 'handle']);

    expect($target->fresh()->error_kind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

it('rechecks Creator freshness after token work before calling a publishing connector', function (): void {
    Notification::fake();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $target = PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id]);
    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->andReturnUsing(function (): array {
        $this->project->forceFill(['revision' => 2])->save();

        return ['access_token' => 'fake-token'];
    });
    app()->instance(TokenManager::class, $tokens);
    bindConnector(fn () => throw new RuntimeException('A stale Creator export must not reach the connector.'));

    app()->call([new PublishPostTarget($target), 'handle']);

    expect($target->fresh()->error_kind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

it('retains Creator provenance and freshness protection when copying a published post', function (): void {
    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $this->post->forceFill(['status' => PostStatus::Published])->save();

    $copy = app(PostDuplicator::class)->duplicate($this->post->fresh());

    expect(CreatorExport::query()->whereIn('post_media_id', $copy->media->pluck('id'))->count())->toBe(2)
        ->and($this->reviews->canPublish($copy))->toBeTrue();
    $this->project->forceFill(['revision' => 2])->save();
    expect($this->reviews->canPublish($copy->fresh()))->toBeFalse();
});

it('rejects source edits while an attached post is actively publishing', function (): void {
    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $this->post->forceFill(['status' => PostStatus::Publishing])->save();
    $document = $this->project->document;
    $document['slides'][0]['background_color'] = '#00ff00';

    $this->putJson(route('creator.projects.update', $this->project), [
        'name' => 'In-flight edit', 'document' => $document, 'expected_revision' => 1,
    ])->assertConflict();

    expect($this->project->fresh()->revision)->toBe(1);
});

it('places exports in the frozen authored thread segment and preserves existing head media', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->post->forceFill(['segments' => ['First section', 'Second section']])->save();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x',
        'sections' => ['First section', 'Second section'], 'section_sources' => [0, 1], 'segment_breaks' => ['break-1'],
    ]);
    $head = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'post_id' => $this->post->id, 'kind' => 'video']);
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['target_segment_ref'] = 'break-1';

    $response = submitCreatorExport($this->post, $payload)->assertCreated();

    expect($target->placements()->where('post_media_id', $head->id)->value('segment_ref'))->toBe('__head__');
    foreach (array_column($response->json('media'), 'id') as $id) {
        expect($target->placements()->where('post_media_id', $id)->value('segment_ref'))->toBe('break-1');
    }
});

it('rejects missing target segments without attaching exports', function (): void {
    $target = PostTarget::factory()->for($this->post)->create(['segment_breaks' => []]);
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['target_segment_ref'] = 'deleted-break';

    submitCreatorExport($this->post, $payload)->assertUnprocessable();

    expect(CreatorExport::query()->count())->toBe(0)
        ->and($target->placements()->count())->toBe(0);
});

it('rejects image exports into a video or Bluesky GIF segment', function (string $kind): void {
    $platform = $kind === 'gif' ? 'bluesky' : 'x';
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => $platform]);
    PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id, 'platform' => $platform]);
    PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'post_id' => $this->post->id,
        'kind' => $kind === 'video' ? 'video' : 'image', 'mime' => $kind === 'video' ? 'video/mp4' : 'image/gif']);

    submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertUnprocessable();

    expect(CreatorExport::query()->count())->toBe(0);
})->with(['video', 'gif']);

it('does not widen per-account override media selections during replacement', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $target = PostTarget::factory()->for($this->post)->create(['connected_account_id' => $account->id]);
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $target->forceFill(['content_override' => ['segments' => ['Custom target copy'], 'media_ids' => [$oldIds[0]]]])->save();
    $target->placements()->where('post_media_id', $oldIds[1])->delete();
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['replace_media_ids'] = $oldIds;

    $second = submitCreatorExport($this->post, $payload)->assertCreated();
    $newIds = array_column($second->json('media'), 'id');

    expect($target->fresh()->content_override)->toBe(['segments' => ['Custom target copy'], 'media_ids' => [$newIds[0]]])
        ->and($target->placements()->pluck('post_media_id')->all())->toBe([$newIds[0]]);
});

it('replaces each slide in its own thread slot even when the design is reordered', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->post->forceFill(['segments' => ['Head', 'Reply']])->save();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Head', 'Reply'],
        'section_sources' => [0, 1], 'segment_breaks' => ['reply-1'],
    ]);
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $keep = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'post_id' => $this->post->id, 'position' => 2]);
    $target->placements()->where('post_media_id', $oldIds[0])->update(['segment_ref' => '__head__', 'position' => 2]);
    $target->placements()->where('post_media_id', $oldIds[1])->update(['segment_ref' => 'reply-1', 'position' => 7]);
    PostMediaPlacement::create(['post_target_id' => $target->id, 'post_media_id' => $keep->id, 'segment_ref' => 'reply-1', 'position' => 4]);
    $document = $this->project->document;
    $document['slides'] = array_reverse($document['slides']);
    $this->putJson(route('creator.projects.update', $this->project), [
        'name' => 'Reordered', 'document' => $document, 'expected_revision' => 1,
    ])->assertOk();
    $payload = creatorExportPayload($this->post, $this->project->fresh());
    $payload['replace_media_ids'] = $oldIds;
    $payload['target_segment_ref'] = '__head__';

    $second = submitCreatorExport($this->post, $payload)->assertCreated();
    $newIds = array_column($second->json('media'), 'id');
    $newBySlide = CreatorExport::query()->whereIn('post_media_id', $newIds)->pluck('post_media_id', 'slide_id');

    expect($target->placements()->where('post_media_id', $newBySlide['slide-1'])->first()->only(['segment_ref', 'position']))
        ->toBe(['segment_ref' => '__head__', 'position' => 2])
        ->and($target->placements()->where('post_media_id', $newBySlide['slide-2'])->first()->only(['segment_ref', 'position']))
        ->toBe(['segment_ref' => 'reply-1', 'position' => 7])
        ->and($target->placements()->where('segment_ref', 'reply-1')->orderBy('position')->pluck('post_media_id')->all())
        ->toBe([$keep->id, $newBySlide['slide-2']]);
});

it('removes deleted slide slots and adds new slides only in the frozen destination', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->post->forceFill(['segments' => ['Head', 'Reply']])->save();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Head', 'Reply'],
        'section_sources' => [0, 1], 'segment_breaks' => ['reply-1'],
    ]);
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $target->placements()->where('post_media_id', $oldIds[1])->update(['segment_ref' => 'reply-1', 'position' => 3]);
    $document = $this->project->document;
    $document['slides'] = [$document['slides'][1], [...$document['slides'][0], 'id' => 'new-slide', 'name' => 'Added']];
    $this->putJson(route('creator.projects.update', $this->project), [
        'name' => 'Changed slides', 'document' => $document, 'expected_revision' => 1,
    ])->assertOk();
    $payload = creatorExportPayload($this->post, $this->project->fresh());
    $payload['replace_media_ids'] = $oldIds;
    $payload['target_segment_ref'] = '__head__';

    $second = submitCreatorExport($this->post, $payload)->assertCreated();
    $newBySlide = CreatorExport::query()->whereIn('post_media_id', array_column($second->json('media'), 'id'))->pluck('post_media_id', 'slide_id');

    expect($target->placements()->whereIn('post_media_id', $oldIds)->count())->toBe(0)
        ->and($target->placements()->where('post_media_id', $newBySlide['slide-2'])->first()->only(['segment_ref', 'position']))
        ->toBe(['segment_ref' => 'reply-1', 'position' => 3])
        ->and($target->placements()->where('post_media_id', $newBySlide['new-slide'])->value('segment_ref'))->toBe('__head__');
});

it('preserves account exclusions and selection ordering when slides are added or reordered', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->post->forceFill(['segments' => ['Head', 'Reply']])->save();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Head', 'Reply'],
        'section_sources' => [0, 1], 'segment_breaks' => ['reply-1'],
    ]);
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $target->placements()->where('post_media_id', $oldIds[0])->delete();
    $target->placements()->where('post_media_id', $oldIds[1])->update(['segment_ref' => 'reply-1', 'position' => 3]);
    $target->forceFill(['content_override' => ['segments' => ['Custom', 'Reply'], 'media_ids' => [$oldIds[1]]]])->save();
    $document = $this->project->document;
    $document['slides'] = [$document['slides'][1], [...$document['slides'][0], 'id' => 'new-slide', 'name' => 'Added'], $document['slides'][0]];
    $this->putJson(route('creator.projects.update', $this->project), [
        'name' => 'Changed slides', 'document' => $document, 'expected_revision' => 1,
    ])->assertOk();
    $payload = creatorExportPayload($this->post, $this->project->fresh());
    $payload['replace_media_ids'] = $oldIds;
    $payload['target_segment_ref'] = '__head__';

    $second = submitCreatorExport($this->post, $payload)->assertCreated();
    $newBySlide = CreatorExport::query()->whereIn('post_media_id', array_column($second->json('media'), 'id'))->pluck('post_media_id', 'slide_id');

    expect($target->fresh()->content_override['media_ids'])->toBe([$newBySlide['slide-2']])
        ->and($target->placements()->pluck('post_media_id')->all())->toBe([$newBySlide['slide-2']])
        ->and($target->placements()->first()->only(['segment_ref', 'position']))->toBe(['segment_ref' => 'reply-1', 'position' => 3]);
});

it('validates mixing in every segment receiving a replacement', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->post->forceFill(['segments' => ['Head', 'Reply']])->save();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Head', 'Reply'],
        'section_sources' => [0, 1], 'segment_breaks' => ['reply-1'],
    ]);
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $target->placements()->where('post_media_id', $oldIds[1])->update(['segment_ref' => 'reply-1']);
    $video = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'post_id' => $this->post->id, 'kind' => 'video']);
    PostMediaPlacement::create(['post_target_id' => $target->id, 'post_media_id' => $video->id, 'segment_ref' => 'reply-1', 'position' => 4]);
    $payload = creatorExportPayload($this->post, $this->project);
    $payload['replace_media_ids'] = $oldIds;

    submitCreatorExport($this->post, $payload)->assertUnprocessable();

    expect(PostMedia::query()->whereIn('id', $oldIds)->where('post_id', $this->post->id)->count())->toBe(2);
});

it('refreshes design order inside each preserved segment without moving unrelated slots', function (): void {
    $document = creatorExportDocument(3);
    $this->project->forceFill(['document' => $document, 'document_hash' => CreatorDocument::hash($document)])->save();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => 'x']);
    $this->post->forceFill(['segments' => ['Head', 'Reply']])->save();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id, 'platform' => 'x', 'sections' => ['Head', 'Reply'],
        'section_sources' => [0, 1], 'segment_breaks' => ['reply-1'],
    ]);
    $first = submitCreatorExport($this->post, creatorExportPayload($this->post, $this->project))->assertCreated();
    $oldIds = array_column($first->json('media'), 'id');
    $target->placements()->where('post_media_id', $oldIds[0])->update(['segment_ref' => '__head__', 'position' => 0]);
    $target->placements()->where('post_media_id', $oldIds[1])->update(['segment_ref' => '__head__', 'position' => 2]);
    $target->placements()->where('post_media_id', $oldIds[2])->update(['segment_ref' => 'reply-1', 'position' => 5]);
    $unrelated = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'post_id' => $this->post->id, 'position' => 3]);
    PostMediaPlacement::create(['post_target_id' => $target->id, 'post_media_id' => $unrelated->id, 'segment_ref' => '__head__', 'position' => 1]);
    $document['slides'] = [$document['slides'][2], $document['slides'][1], $document['slides'][0]];
    $this->putJson(route('creator.projects.update', $this->project), [
        'name' => 'Reordered carousel', 'document' => $document, 'expected_revision' => 1,
    ])->assertOk();
    $payload = creatorExportPayload($this->post, $this->project->fresh());
    $payload['replace_media_ids'] = $oldIds;
    $payload['target_segment_ref'] = '__head__';

    $second = submitCreatorExport($this->post, $payload)->assertCreated();
    $newBySlide = CreatorExport::query()->whereIn('post_media_id', array_column($second->json('media'), 'id'))->pluck('post_media_id', 'slide_id');

    expect($target->placements()->where('segment_ref', '__head__')->orderBy('position')->pluck('post_media_id')->all())
        ->toBe([$newBySlide['slide-2'], $unrelated->id, $newBySlide['slide-1']])
        ->and($target->placements()->where('post_media_id', $unrelated->id)->value('position'))->toBe(1)
        ->and($target->placements()->where('post_media_id', $newBySlide['slide-3'])->first()->only(['segment_ref', 'position']))
        ->toBe(['segment_ref' => 'reply-1', 'position' => 5]);
});
