<?php

use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

test('a stale company tab cannot create its draft in the newly active company', function (): void {
    [$user, $original] = ownerActingIn();
    $other = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner]);
    $user->forceFill(['current_workspace_id' => $other->id])->save();

    $this->postJson('/posts', ['expected_workspace_id' => $original->id, 'segments' => ['Original company draft'], 'destination' => ['kind' => 'none']])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    expect(Post::withoutGlobalScopes()->count())->toBe(0);
});

test('matching company context permits a new draft and legacy clients retain their active-workspace contract', function (): void {
    [$user, $workspace] = ownerActingIn();
    $payload = ['segments' => ['Draft'], 'destination' => ['kind' => 'none']];
    $this->postJson('/posts', [...$payload, 'expected_workspace_id' => $workspace->id])->assertCreated();
    $this->postJson('/posts', $payload)->assertCreated();
    expect(Post::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(2);
});
