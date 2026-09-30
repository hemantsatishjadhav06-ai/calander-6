<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

/**
 * A workspace member whose current workspace + request Context are set, so
 * post route-model binding resolves and the PostPolicy passes.
 *
 * @return array{0: User, 1: Workspace}
 */
function duplicateMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    return [$user, $workspace];
}

/**
 * A published post with one image (real fake file) and one published target
 * carrying a per-account content override that references the media.
 */
function publishedPostWithMediaAndTarget(Workspace $workspace, User $user): Post
{
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'segments' => ['Hello world'],
        'base_text' => 'Hello world',
        'mentions' => [],
        'status' => PostStatus::Published->value,
        'published_at' => now(),
        'auto_repost' => true,
    ]);

    Storage::disk('public')->put('media/original.jpg', 'IMG');
    $media = PostMedia::factory()->for($workspace)->create([
        'post_id' => $post->id,
        'disk' => 'public',
        'path' => 'media/original.jpg',
        'position' => 0,
    ]);

    PostTarget::factory()->published()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'content_override' => ['text' => 'Custom', 'media_ids' => [$media->id]],
    ]);

    return $post->load('media', 'targets');
}

beforeEach(function (): void {
    Storage::fake('public');
    [$this->user, $this->workspace] = duplicateMember();
});

test('duplicating a published post creates a reset draft copy', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);

    $response = $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();

    $response->assertRedirect(route('posts.show', $draft));
    expect($draft->id)->not->toBe($post->id)
        ->and($draft->workspace_id)->toBe($this->workspace->id)
        ->and($draft->segments)->toBe(['Hello world'])
        ->and($draft->auto_repost)->toBeTrue()
        ->and($draft->scheduled_at)->toBeNull()
        ->and($draft->published_at)->toBeNull();
});

test('cloned media is a new file that survives deleting the original post', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);

    $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();
    $copy = $draft->media()->firstOrFail();

    expect($copy->path)->not->toBe('media/original.jpg')
        ->and(Storage::disk('public')->exists($copy->path))->toBeTrue();

    $post->media()->firstOrFail()->delete();

    expect(Storage::disk('public')->exists($copy->path))->toBeTrue();
});

test('cloned target is pending with overrides preserved and media ids remapped', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);

    $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();
    $target = $draft->targets()->firstOrFail();
    $newMedia = $draft->media()->firstOrFail();

    expect($target->status)->toBe(PostTargetStatus::Pending)
        ->and($target->remote_id)->toBeNull()
        ->and($target->posted_at)->toBeNull()
        ->and($target->content_override['text'])->toBe('Custom')
        ->and($target->content_override['media_ids'])->toBe([$newMedia->id]);
});

test('targets for deleted accounts are skipped', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);
    $post->targets()->firstOrFail()->account->forceDelete();

    $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();

    expect($draft->targets()->count())->toBe(0);
});

test('a draft post is not eligible to be copied', function (): void {
    $post = Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id,
        'status' => PostStatus::Draft->value,
    ]);

    $this->actingAs($this->user)
        ->post(route('posts.duplicate', $post))
        ->assertStatus(422);

    expect(Post::query()->where('status', PostStatus::Draft->value)->count())->toBe(1);
});

test('a post from another workspace cannot be duplicated', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);
    [$other] = duplicateMember();

    $this->actingAs($other)
        ->post(route('posts.duplicate', $post))
        ->assertNotFound();
});

test('duplicating a thread preserves section breaks sources and remapped media placements', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);
    $target = $post->targets()->firstOrFail();
    $target->forceFill(['sections' => ['Head', 'Reply'], 'segment_breaks' => ['0'], 'section_sources' => [0, 1]])->save();
    $head = $post->media()->firstOrFail();
    Storage::disk('public')->put('media/reply.jpg', 'IMG2');
    $reply = PostMedia::factory()->for($this->workspace)->create(['post_id' => $post->id, 'disk' => 'public', 'path' => 'media/reply.jpg', 'position' => 1]);
    foreach ([[$head, '0', 0], [$reply, '1', 2]] as [$media, $segment, $position]) {
        PostMediaPlacement::create(['post_target_id' => $target->id, 'post_media_id' => $media->id, 'segment_ref' => $segment, 'position' => $position]);
    }
    $this->actingAs($this->user)->post(route('posts.duplicate', $post))->assertRedirect();
    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();
    $copy = $draft->targets()->firstOrFail();
    $media = $draft->media()->orderBy('position')->get();
    expect($copy->sections)->toBe(['Head', 'Reply'])
        ->and($copy->segment_breaks)->toBe(['0'])
        ->and($copy->section_sources)->toBe([0, 1])
        ->and($copy->placements()->orderBy('segment_ref')->get()->map(fn ($placement) => [$placement->post_media_id, $placement->segment_ref, $placement->position])->all())
        ->toBe([[$media[0]->id, '0', 0], [$media[1]->id, '1', 2]]);
});
