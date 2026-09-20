<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * The workspace global scope adds no constraint when no workspace context is
 * set, and WorkspaceMiddleware only sets that context when the user has a
 * current_workspace_id. A user can reach a null current_workspace_id by leaving
 * their last workspace, so the workspace-scoped read paths must constrain
 * explicitly rather than relying on the global scope.
 */
function inertiaVersionForLeakTest(): string
{
    return (string) app(HandleInertiaRequests::class)->version(request());
}

test('a user with no current workspace sees no other tenant posts on the dashboard', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'base_text' => 'strictly-private-tenant-content',
    ]);

    $user = User::factory()->create(['current_workspace_id' => null]);

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => inertiaVersionForLeakTest(),
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'posts',
        ])
        ->get(route('dashboard'));

    $response->assertOk();

    expect($response->json('props.posts'))->toBe([]);
    expect($response->getContent())->not->toContain('strictly-private-tenant-content');
});

test('a user still sees their own workspace posts on the dashboard', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'base_text' => 'my-own-tenant-content',
    ]);

    $otherWorkspace = Workspace::factory()->create();
    Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'base_text' => 'strictly-private-tenant-content',
    ]);

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => inertiaVersionForLeakTest(),
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'posts',
        ])
        ->get(route('dashboard'));

    $response->assertOk();

    expect($response->getContent())
        ->toContain('my-own-tenant-content')
        ->not->toContain('strictly-private-tenant-content');
});

test('the engagement inbox refuses a request with no current workspace', function (): void {
    $user = User::factory()->create(['current_workspace_id' => null]);

    // 403 from the workspace guard, or 404 if engagement is disabled on this
    // instance. Either way the inbox must not render another tenant's data.
    $status = $this->actingAs($user)->get(route('engagement.index'))->getStatusCode();

    expect($status)->toBeIn([403, 404]);
});
