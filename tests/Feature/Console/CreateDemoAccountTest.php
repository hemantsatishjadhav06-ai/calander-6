<?php

use App\Enums\InstanceRole;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Hash;

test('it provisions a demo user in its own workspace with sample posts', function () {
    $this->artisan('demo:create')->assertSuccessful();

    $user = User::query()->where('email', 'demo@example.com')->firstOrFail();
    $workspace = Workspace::query()->where('slug', 'demo')->firstOrFail();

    expect($user->name)->toBe('Demo User')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->current_workspace_id)->toBe($workspace->id)
        ->and($workspace->owner_id)->toBe($user->id);

    $membership = WorkspaceMembership::query()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->role)->toBe(WorkspaceRole::Owner);

    $posts = Post::query()->withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->get();

    expect($posts)->toHaveCount(4)
        ->and($posts->pluck('status')->unique()->sort()->values()->all())
        ->toEqual([PostStatus::Draft, PostStatus::Scheduled]);
});

/**
 * The demo login is handed out publicly, so it must never carry instance-wide
 * powers — that is the whole difference between it and the dev seeder.
 */
test('the demo user is not an instance owner', function () {
    $this->artisan('demo:create')->assertSuccessful();

    $user = User::query()->where('email', 'demo@example.com')->firstOrFail();

    expect($user->instance_role)->toBeNull()
        ->and($user->isInstanceOwner())->toBeFalse();
});

test('it issues a different password on every run and never reuses a known one', function () {
    $this->artisan('demo:create')->assertSuccessful();
    $first = User::query()->where('email', 'demo@example.com')->firstOrFail()->password;

    $this->artisan('demo:create')->assertSuccessful();
    $second = User::query()->where('email', 'demo@example.com')->firstOrFail()->password;

    expect($second)->not->toBe($first)
        ->and(Hash::check('password', $second))->toBeFalse();
});

test('re-running does not duplicate the workspace, membership, or sample posts', function () {
    $this->artisan('demo:create')->assertSuccessful();
    $this->artisan('demo:create')->assertSuccessful();

    expect(User::query()->where('email', 'demo@example.com')->count())->toBe(1)
        ->and(Workspace::query()->count())->toBe(1)
        ->and(WorkspaceMembership::query()->count())->toBe(1)
        ->and(Post::query()->withoutGlobalScopes()->count())->toBe(4);
});

test('it refuses to turn the instance owner into a demo account', function () {
    $owner = User::factory()->create([
        'email' => 'owner@example.com',
        'instance_role' => InstanceRole::Owner,
    ]);
    $originalPassword = $owner->password;

    $this->artisan('demo:create', ['--email' => 'owner@example.com'])->assertFailed();

    expect($owner->fresh()->password)->toBe($originalPassword);
});

test('it takes an explicit email and name', function () {
    $this->artisan('demo:create', [
        '--email' => 'try@sm-manager.test',
        '--name' => 'Try SM Manager',
    ])->assertSuccessful();

    expect(User::query()->where('email', 'try@sm-manager.test')->firstOrFail()->name)
        ->toBe('Try SM Manager');
});

/**
 * Two demo logins must not share an inbox: the workspace is keyed on its owner,
 * not on a fixed slug.
 */
test('a second demo email gets a workspace of its own', function () {
    $this->artisan('demo:create')->assertSuccessful();
    $this->artisan('demo:create', ['--email' => 'demo2@example.com'])->assertSuccessful();

    $first = User::query()->where('email', 'demo@example.com')->firstOrFail();
    $second = User::query()->where('email', 'demo2@example.com')->firstOrFail();

    expect($second->current_workspace_id)->not->toBe($first->current_workspace_id)
        ->and(Workspace::query()->pluck('slug')->sort()->values()->all())
        ->toEqual(['demo', 'demo-2']);
});
