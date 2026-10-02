<?php

declare(strict_types=1);

use App\Http\Middleware\WorkspaceMiddleware;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

/**
 * HasWorkspaceScope adds no constraint when no workspace context is bound, so
 * an HTTP request that reached a scoped query without one read every tenant's
 * rows. WorkspaceMiddleware now always binds a workspace for an authenticated
 * request. The scope stays inert outside HTTP on purpose — see the middleware
 * docblock — and the last test here guards that.
 */
test('a bound no-workspace context matches no rows', function (): void {
    Post::factory()->create(['base_text' => 'another tenant private post']);

    Context::add('workspace_id', WorkspaceMiddleware::NO_WORKSPACE);

    expect(Post::query()->count())->toBe(0);

    Context::flush();
});

test('the middleware binds the sentinel for a user with no workspace at all', function (): void {
    $user = User::factory()->create(['current_workspace_id' => null]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(Context::get('workspace_id'))->toBe(WorkspaceMiddleware::NO_WORKSPACE);
});

test('a user with no workspace reads none of another tenant posts', function (): void {
    $other = Workspace::factory()->create();
    Post::factory()->create([
        'workspace_id' => $other->id,
        'base_text' => 'another tenant private post',
    ]);

    $user = User::factory()->create(['current_workspace_id' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())->not->toContain('another tenant private post');
});

test('the middleware repairs a null current workspace from an existing membership', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => null]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
    expect(Context::get('workspace_id'))->toBe($workspace->id);
});

test('a revoked current workspace cannot be read through dashboard or search', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $membership = WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'base_text' => 'revoked-workspace-private-content',
    ]);
    $membership->delete();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('revoked-workspace-private-content');
    $this->getJson(route('command-search', ['q' => 'revoked-workspace']))
        ->assertOk()
        ->assertJsonPath('posts', []);

    expect($user->fresh()->current_workspace_id)->toBeNull();
    expect(Context::get('workspace_id'))->toBe(WorkspaceMiddleware::NO_WORKSPACE);
});

test('a stale current workspace is repaired before binding a revoked workspace post', function (): void {
    $revokedWorkspace = Workspace::factory()->create();
    $remainingWorkspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $revokedWorkspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $remainingWorkspace->id,
        'user_id' => $user->id,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $revokedWorkspace->id,
        'base_text' => 'revoked-workspace-private-content',
    ]);

    $this->actingAs($user)->get(route('posts.show', $post))->assertNotFound();

    expect($user->fresh()->current_workspace_id)->toBe($remainingWorkspace->id);
});

test('repairing a current workspace permits binding a post in the remaining workspace', function (): void {
    $revokedWorkspace = Workspace::factory()->create();
    $remainingWorkspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $revokedWorkspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $remainingWorkspace->id,
        'user_id' => $user->id,
    ]);
    $post = Post::factory()->create(['workspace_id' => $remainingWorkspace->id]);

    $this->actingAs($user)->get(route('posts.show', $post))->assertOk();
});

/**
 * Regression guard. The scheduler claims due posts for every tenant and
 * PublishPostTarget resolves the scoped `post`/`account` relations, all with no
 * workspace context. Constraining the scope there would stop publishing.
 */
test('an unbound context still reads across every workspace', function (): void {
    Context::flush();

    Post::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    Post::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    expect(Post::query()->count())->toBe(2);
});
