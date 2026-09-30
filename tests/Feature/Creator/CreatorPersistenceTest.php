<?php

declare(strict_types=1);

use App\Models\CreatorAsset;
use App\Models\CreatorProject;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function creatorPersistenceDocument(): array
{
    return [
        'schema_version' => 1,
        'canvas' => ['width' => 1080, 'height' => 1080],
        'slides' => [[
            'id' => (string) Str::uuid(),
            'name' => 'Opening slide',
            'background_color' => '#ffffff',
            'layers' => [],
        ]],
    ];
}

/** @return array<string, mixed> */
function creatorPersistenceImageLayer(string $assetId): array
{
    return [
        'id' => (string) Str::uuid(),
        'type' => 'image',
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
        'asset_id' => $assetId,
        'fit' => 'contain',
    ];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['filesystems.default' => 'local', 'filesystems.public_images' => 'public']);
    Storage::fake('local');
    Storage::fake('public');
    [$this->actor, $this->workspace] = ownerActingIn();
});

it('requires authentication for Creator project and asset endpoints', function (): void {
    Auth::logout();

    $this->getJson(route('creator.projects.index'))->assertUnauthorized();
    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Private design', 'document' => creatorPersistenceDocument(),
    ])->assertUnauthorized();
    $this->getJson(route('creator.assets.index'))->assertUnauthorized();
    $this->post(route('creator.assets.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Logo', 'kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png'),
    ], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('fails closed when an authenticated user has no workspace', function (): void {
    $privateProject = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $privateAsset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id]);
    $user = User::factory()->create(['current_workspace_id' => null]);
    Context::flush();
    $this->actingAs($user);

    $this->getJson(route('creator.projects.index'))->assertForbidden();
    $this->getJson(route('creator.assets.index'))->assertForbidden();
    $this->getJson(route('creator.projects.show', $privateProject))->assertNotFound();
    $this->getJson(route('creator.assets.content', $privateAsset))->assertNotFound();
    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'No workspace', 'document' => creatorPersistenceDocument(),
    ])->assertForbidden();

    expect(CreatorProject::withoutGlobalScopes()->count())->toBe(1);
});

it('persists and reloads a workspace-owned versioned design document', function (): void {
    $document = creatorPersistenceDocument();
    $response = $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Launch carousel',
        'document' => $document,
        'workspace_id' => (string) Str::uuid(),
        'created_by_id' => (string) Str::uuid(),
        'revision' => 999,
    ])->assertCreated()
        ->assertJsonPath('project.name', 'Launch carousel')
        ->assertJsonPath('project.revision', 1)
        ->assertJsonPath('project.document', $document);

    $project = CreatorProject::withoutGlobalScopes()->findOrFail($response->json('project.id'));
    expect($project->workspace_id)->toBe($this->workspace->id)
        ->and($project->created_by_id)->toBe($this->actor->id);

    $this->getJson(route('creator.projects.show', $project))->assertOk()
        ->assertJsonPath('project.document', $document);
    $this->getJson(route('creator.projects.index'))->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.id', $project->id);
});

it('never exposes another workspace projects or asset bytes', function (): void {
    $own = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreign = CreatorProject::factory()->create();
    $foreignAsset = CreatorAsset::factory()->create(['workspace_id' => $foreign->workspace_id]);
    Storage::disk($foreignAsset->disk)->put($foreignAsset->path, 'private asset bytes');

    $this->getJson(route('creator.projects.index'))->assertOk()
        ->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.id', $own->id);
    $this->getJson(route('creator.assets.index'))->assertOk()->assertJsonCount(0, 'assets');
    $this->getJson(route('creator.projects.show', $foreign))->assertNotFound();
    $this->putJson(route('creator.projects.update', $foreign), [
        'name' => 'Hijacked', 'document' => creatorPersistenceDocument(), 'expected_revision' => 1,
    ])->assertNotFound();
    $this->getJson(route('creator.assets.content', $foreignAsset))->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('Hijacked');
});

it('uses optimistic revisions and leaves a stale save unchanged', function (): void {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $document = $project->document;
    $document['slides'][0]['background_color'] = '#123456';

    $this->putJson(route('creator.projects.update', $project), [
        'name' => 'First save', 'document' => $document, 'expected_revision' => 1,
    ])->assertOk()->assertJsonPath('project.revision', 2);

    $stale = $document;
    $stale['slides'][0]['background_color'] = '#000000';
    $this->putJson(route('creator.projects.update', $project), [
        'name' => 'Stale save', 'document' => $stale, 'expected_revision' => 1,
    ])->assertConflict();

    expect($project->fresh()->revision)->toBe(2)
        ->and($project->fresh()->name)->toBe('First save')
        ->and($project->fresh()->document)->toBe($document);
});

it('requires an expected revision when updating a design', function (): void {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->putJson(route('creator.projects.update', $project), [
        'name' => 'Missing revision', 'document' => $project->document,
    ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
});

it('rejects unsupported schema data and bounded-document violations', function (string $case): void {
    $document = creatorPersistenceDocument();
    switch ($case) {
        case 'schema':
            $document['schema_version'] = 2;
            break;
        case 'canvas':
            $document['canvas']['width'] = 4097;
            break;
        case 'empty slides':
            $document['slides'] = [];
            break;
        case 'non-array slides':
            $document['slides'] = 'invalid';
            break;
        case 'non-array layers':
            $document['slides'][0]['layers'] = 'invalid';
            break;
        case 'duplicate slides':
            $document['slides'][] = $document['slides'][0];
            break;
        case 'unsupported root':
            $document['script'] = 'alert(1)';
            break;
        case 'unsafe color':
            $document['slides'][0]['background_color'] = 'url(https://example.com/image)';
            break;
        case 'excess slides':
            $document['slides'] = array_map(fn (int $index): array => [
                ...$document['slides'][0], 'id' => 'slide-'.$index,
            ], range(1, 11));
            break;
    }

    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Invalid design', 'document' => $document,
    ])->assertUnprocessable();
    expect(CreatorProject::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['schema', 'canvas', 'empty slides', 'non-array slides', 'non-array layers', 'duplicate slides', 'unsupported root', 'unsafe color', 'excess slides']);

it('only accepts image references saved in the current workspace', function (string $case): void {
    $asset = CreatorAsset::factory()->create([
        'workspace_id' => $case === 'foreign' ? CreatorProject::factory()->create()->workspace_id : $this->workspace->id,
    ]);
    $document = creatorPersistenceDocument();
    $layer = creatorPersistenceImageLayer($case === 'missing' ? (string) Str::uuid() : $asset->id);
    if ($case === 'remote URL') {
        $layer['src'] = 'https://example.com/private.png';
    }
    if ($case === 'data URL') {
        $layer['asset_id'] = 'data:image/png;base64,aGVsbG8=';
    }
    if ($case === 'path') {
        $layer['path'] = '../../secret.png';
    }
    $document['slides'][0]['layers'][] = $layer;

    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Invalid reference', 'document' => $document,
    ])->assertUnprocessable();
    expect(CreatorProject::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['foreign', 'missing', 'remote URL', 'data URL', 'path']);

it('saves reusable logo assets with an authenticated content URL', function (): void {
    $response = $this->post(route('creator.assets.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Brand logo', 'kind' => 'logo',
        'file' => UploadedFile::fake()->image('logo.png', 64, 64),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('asset.kind', 'logo')
        ->assertJsonPath('asset.mime', 'image/png');

    $asset = CreatorAsset::withoutGlobalScopes()->findOrFail($response->json('asset.id'));
    expect($asset->workspace_id)->toBe($this->workspace->id)
        ->and($response->json('asset.content_url'))->toBe(route('creator.assets.content', $asset));
    Storage::disk($asset->disk)->assertExists($asset->path);
    $this->get(route('creator.assets.content', $asset))->assertOk()->assertHeader('Content-Type', 'image/png');

    $document = creatorPersistenceDocument();
    $document['slides'][0]['layers'][] = [...creatorPersistenceImageLayer($asset->id), 'role' => 'logo'];
    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Reusable logo design', 'document' => $document,
    ])->assertCreated()->assertJsonPath('project.document.slides.0.layers.0.asset_id', $asset->id);

    Auth::logout();
    $this->getJson(route('creator.assets.content', $asset))->assertUnauthorized();
});

it('rejects SVG and disguised non-image asset uploads', function (string $extension, string $mime): void {
    $this->post(route('creator.assets.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Untrusted upload', 'kind' => 'image',
        'file' => UploadedFile::fake()->create('payload.'.$extension, 2, $mime),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(CreatorAsset::query()->count())->toBe(0);
})->with([['svg', 'image/svg+xml'], ['png', 'text/html']]);

it('keeps saved reusable assets when abandoned post uploads are pruned', function (): void {
    $response = $this->post(route('creator.assets.store'), ['expected_workspace_id' => $this->workspace->id,
        'name' => 'Saved logo', 'kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 64, 64),
    ], ['Accept' => 'application/json'])->assertCreated();
    $asset = CreatorAsset::withoutGlobalScopes()->findOrFail($response->json('asset.id'));
    $asset->forceFill(['created_at' => now()->subDay()])->save();
    $orphan = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id, 'post_id' => null, 'direct_message_id' => null,
        'created_at' => now()->subDay(), 'disk' => 'public', 'path' => 'media/abandoned.png',
    ]);
    Storage::disk('public')->put($orphan->path, 'abandoned');

    $this->artisan('media:prune-uploads')->assertSuccessful();

    $this->assertModelExists($asset);
    $this->assertModelMissing($orphan);
    Storage::disk($asset->disk)->assertExists($asset->path);
    $this->get(route('creator.assets.content', $asset))->assertOk();
});

it('preserves authored text whitespace and empty text layers', function (string $text): void {
    $document = creatorPersistenceDocument();
    $document['slides'][0]['layers'][] = [
        'id' => 'text-layer', 'type' => 'text', 'name' => '', 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 100,
        'text' => $text, 'font_family' => 'Arial', 'font_size' => 32, 'font_weight' => 400,
        'color' => '#000000', 'text_align' => 'left',
    ];

    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id, 'name' => 'Text fidelity', 'document' => $document])
        ->assertCreated()->assertJsonPath('project.document.slides.0.layers.0.text', $text);
})->with(['', "  first line\nsecond line  "]);

it('rejects non-string layer types as validation errors', function (): void {
    $document = creatorPersistenceDocument();
    $document['slides'][0]['layers'][] = ['id' => 'bad-type', 'type' => ['image'], 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10];

    $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $this->workspace->id, 'name' => 'Malformed layer', 'document' => $document])->assertUnprocessable();
});

it('requires the initiating workspace on contextless project and asset writes', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    foreach ([null, $otherWorkspace->id] as $expected) {
        $this->postJson(route('creator.projects.store'), ['expected_workspace_id' => $expected,
            'name' => 'Stale tab', 'document' => creatorPersistenceDocument(),
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
        $this->post(route('creator.assets.store'), ['expected_workspace_id' => $expected,
            'name' => 'Stale tab logo', 'kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    }

    expect(CreatorProject::query()->count())->toBe(0)->and(CreatorAsset::query()->count())->toBe(0);
});

it('paginates all projects stably across equal creation timestamps', function (): void {
    $createdAt = now()->startOfSecond();
    CreatorProject::factory()->count(45)->create(['workspace_id' => $this->workspace->id, 'created_at' => $createdAt]);
    CreatorProject::factory()->create(['name' => 'Foreign result', 'created_at' => $createdAt]);
    $expectedIds = CreatorProject::query()->where('workspace_id', $this->workspace->id)
        ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    $first = $this->getJson(route('creator.projects.index'))->assertOk()->assertJsonCount(40, 'projects')->assertJsonMissingPath('projects.0.document');
    expect($first->json('workspace_id'))->toBe($this->workspace->id)->and($first->json('next_cursor'))->toBeString();
    $second = $this->getJson(route('creator.projects.index', ['cursor' => $first->json('next_cursor')]))
        ->assertOk()->assertJsonCount(5, 'projects')->assertJsonPath('next_cursor', null);

    expect([...array_column($first->json('projects'), 'id'), ...array_column($second->json('projects'), 'id')])->toBe($expectedIds);
});

it('paginates all assets without duplicates or cross-workspace rows', function (): void {
    $createdAt = now()->startOfSecond();
    CreatorAsset::factory()->count(55)->create(['workspace_id' => $this->workspace->id, 'created_at' => $createdAt]);
    CreatorAsset::factory()->create(['name' => 'Foreign asset', 'created_at' => $createdAt]);
    $expectedIds = CreatorAsset::query()->where('workspace_id', $this->workspace->id)
        ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    $first = $this->getJson(route('creator.assets.index'))->assertOk()->assertJsonCount(50, 'assets');
    $second = $this->getJson(route('creator.assets.index', ['cursor' => $first->json('next_cursor')]))
        ->assertOk()->assertJsonCount(5, 'assets')->assertJsonPath('next_cursor', null);

    expect([...array_column($first->json('assets'), 'id'), ...array_column($second->json('assets'), 'id')])->toBe($expectedIds);
});

it('searches names within the current workspace across both Creator libraries', function (): void {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Autumn Launch']);
    $asset = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Autumn Logo']);
    CreatorProject::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Summer']);
    CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Summer']);
    CreatorProject::factory()->create(['name' => 'Autumn foreign']);
    CreatorAsset::factory()->create(['name' => 'Autumn foreign']);

    $this->getJson(route('creator.projects.index', ['search' => 'Autumn']))->assertOk()
        ->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.id', $project->id);
    $this->getJson(route('creator.assets.index', ['search' => 'Autumn']))->assertOk()
        ->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $asset->id);
    $this->getJson(route('creator.projects.index', ['search' => "' OR 1=1 --"]))->assertOk()->assertJsonCount(0, 'projects');
});

it('rejects malformed library cursors and excessive search strings', function (string $route): void {
    $this->getJson(route($route, ['search' => str_repeat('x', 201)]))->assertUnprocessable()->assertJsonValidationErrors('search');
    $this->getJson(route($route, ['cursor' => 'not-a-cursor']))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $invalidId = (new Cursor(['created_at' => '2026-09-30 12:00:00', 'id' => 'not-a-uuid']))->encode();
    $this->getJson(route($route, ['cursor' => $invalidId]))->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with(['creator.projects.index', 'creator.assets.index']);
