<?php

use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\DerivedMedia;
use App\Services\Posts\MediaStorageService;
use App\Support\SafeImageFetcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => config(['filesystems.default' => 'public']));

test('it stores an uploaded image as workspace-scoped orphan media', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    Context::add('workspace_id', $workspace->id);

    $file = UploadedFile::fake()->image('photo.jpg', 1200, 800)->size(400);

    $media = app(MediaStorageService::class)->store($workspace->id, $file);

    expect($media->post_id)->toBeNull()
        ->and($media->workspace_id)->toBe($workspace->id)
        ->and($media->disk)->toBe('s3')
        ->and($media->mime)->toBe('image/jpeg')
        ->and($media->width)->toBe(1200);

    Storage::disk('s3')->assertExists($media->path);
});

test('storeBeautified persists composed + source files and settings', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');
    $workspace = Workspace::factory()->create();

    $media = app(MediaStorageService::class)->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('composed.png', 800, 600),
        UploadedFile::fake()->image('source.png', 1200, 900),
        ['version' => 1, 'padding' => 64],
    );

    Storage::disk('s3')->assertExists($media->path);
    Storage::disk('s3')->assertExists($media->source_path);
    expect($media->edit_settings)->toBe(['version' => 1, 'padding' => 64])
        ->and($media->disk)->toBe('s3')
        ->and($media->source_disk)->toBe('s3')
        ->and($media->workspace_id)->toBe($workspace->id)
        // The returned instance must carry kind (not rely on the DB default), or
        // toView() serializes null and the client can't tell it's an image.
        ->and($media->kind)->toBe('image');
});

test('replaceBeautified swaps the composed file and settings but keeps the source', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $service = app(MediaStorageService::class);

    $media = $service->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('c1.png', 400, 400),
        UploadedFile::fake()->image('s.png', 800, 800),
        ['version' => 1, 'padding' => 10],
    );
    $oldPath = $media->path;
    $sourcePath = $media->source_path;

    $updated = $service->replaceBeautified(
        $media,
        UploadedFile::fake()->image('c2.png', 500, 500),
        ['version' => 1, 'padding' => 99],
    );

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($updated->path);
    Storage::disk('public')->assertExists($sourcePath);
    expect($updated->path)->not->toBe($oldPath)
        ->and($updated->source_path)->toBe($sourcePath)
        ->and($updated->edit_settings)->toBe(['version' => 1, 'padding' => 99]);
});

test('failed image writes do not create an orphan media record', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $file = Mockery::mock(UploadedFile::fake()->image('photo.jpg'))->makePartial();
    $file->shouldReceive('store')->once()->andReturn(false);

    expect(fn () => app(MediaStorageService::class)->store($workspace->id, $file))
        ->toThrow(RuntimeException::class, 'Could not store the uploaded image.');

    expect(PostMedia::count())->toBe(0);
    Storage::disk('public')->assertEmpty();
});

test('failed downloaded image writes do not create a media record', function () {
    $workspace = Workspace::factory()->create();
    $fetcher = Mockery::mock(SafeImageFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->with('https://images.example/photo.png')->andReturn([
        'bytes' => transparentPng(),
        'mime' => 'image/png',
    ]);
    $disk = Mockery::mock(Storage::fake('public'))->makePartial();
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    expect(fn () => new MediaStorageService($fetcher)->storeFromUrl($workspace->id, 'https://images.example/photo.png'))
        ->toThrow(RuntimeException::class, 'Could not store the downloaded image.');

    expect(PostMedia::count())->toBe(0);
});

test('a failed replacement preserves the current file and media settings', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $service = app(MediaStorageService::class);
    $media = $service->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('composed.png'),
        UploadedFile::fake()->image('source.png'),
        ['version' => 1, 'padding' => 10],
    );
    $oldPath = $media->path;
    $file = Mockery::mock(UploadedFile::fake()->image('replacement.png'))->makePartial();
    $file->shouldReceive('store')->once()->andReturn(false);

    expect(fn () => $service->replaceBeautified($media, $file, ['version' => 1, 'padding' => 99]))
        ->toThrow(RuntimeException::class, 'Could not store the uploaded image.');

    expect($media->refresh()->path)->toBe($oldPath)
        ->and($media->edit_settings)->toBe(['version' => 1, 'padding' => 10]);
    Storage::disk('public')->assertExists($oldPath);
    Storage::disk('public')->assertExists($media->source_path);
});

test('a failed source upload cleans up the composed image', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $source = Mockery::mock(UploadedFile::fake()->image('source.png'))->makePartial();
    $source->shouldReceive('store')->once()->andReturn(false);

    expect(fn () => app(MediaStorageService::class)->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('composed.png'),
        $source,
        ['version' => 1, 'padding' => 10],
    ))->toThrow(RuntimeException::class, 'Could not store the uploaded image.');

    expect(PostMedia::count())->toBe(0);
    Storage::disk('public')->assertEmpty();
});

test('replacing an edited image invalidates its cached publish conversions', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $service = app(MediaStorageService::class);
    $media = $service->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('composed.png'),
        UploadedFile::fake()->image('source.png'),
        ['version' => 1, 'padding' => 10],
    );
    foreach (DerivedMedia::pathsFor($media) as $path) {
        Storage::disk('public')->put($path, 'cached original conversion');
    }

    $service->replaceBeautified($media, UploadedFile::fake()->image('new.png'), ['version' => 1, 'padding' => 20]);

    foreach (DerivedMedia::pathsFor($media) as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});
