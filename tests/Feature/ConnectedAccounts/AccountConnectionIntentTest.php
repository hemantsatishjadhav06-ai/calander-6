<?php

use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ConnectedAccounts\BlueskyOAuthConnector;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

beforeEach(function () {
    config([
        'services.x.client_id' => 'cid',
        'services.x.client_secret' => 'secret',
        'services.facebook.client_id' => 'cid',
        'services.facebook.client_secret' => 'secret',
        'services.linkedin-openid.client_id' => 'cid',
        'services.linkedin-openid.client_secret' => 'secret',
    ]);
});

function secondManagedWorkspace(User $user): Workspace
{
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);

    return $workspace;
}

function mockConnectionRedirect(string $driver): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('fields')->andReturnSelf();
    $provider->shouldReceive('usingGraphVersion')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect('https://provider.example/authorize?state=initiating-state'));
    $provider->shouldNotReceive('user');
    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
}

test('a generic oauth callback cannot use credentials after switching workspaces', function () {
    [$user] = ownerActingIn();
    mockConnectionRedirect('x');
    $this->get('/accounts/connect/x')->assertRedirect();

    $user->forceFill(['current_workspace_id' => secondManagedWorkspace($user)->id])->save();
    $this->actingAs($user)->get('/accounts/callback/x?code=code&state=initiating-state')
        ->assertRedirect(route('accounts.index'))->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

test('meta callbacks require the state of a connection started by this session', function (?string $state) {
    ownerActingIn();
    mockConnectionRedirect('facebook');
    $this->get(route('accounts.meta.redirect'))->assertRedirect();

    $this->get(route('accounts.meta.callback', ['code' => 'attacker-code', 'state' => $state]))
        ->assertRedirect(route('accounts.index'))->assertSessionHas('error');

    expect(session('accounts.meta.connect'))->toBeNull();
    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
})->with([null, 'attacker-state']);

test('meta callbacks cannot move an initiated connection to another workspace', function () {
    [$user] = ownerActingIn();
    mockConnectionRedirect('facebook');
    $this->get(route('accounts.meta.redirect'))->assertRedirect();

    $user->forceFill(['current_workspace_id' => secondManagedWorkspace($user)->id])->save();
    $this->actingAs($user)->get(route('accounts.meta.callback', ['code' => 'code', 'state' => 'initiating-state']))
        ->assertRedirect(route('accounts.index'))->assertSessionHas('error');

    expect(session('accounts.meta.connect'))->toBeNull();
    Http::assertNothingSent();
});

test('account connections reject an expired or different-user initiation', function (string $mismatch) {
    [$user, $workspace] = ownerActingIn();
    mockConnectionRedirect('x');
    $this->get('/accounts/connect/x')->assertRedirect();

    if ($mismatch === 'expired') {
        $this->travel(31)->minutes();
    } else {
        $other = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => $other->id,
            'workspace_id' => $workspace->id,
            'role' => WorkspaceRole::Admin,
        ]);
        $other->forceFill(['current_workspace_id' => $workspace->id])->save();
        $this->actingAs($other);
    }

    $this->get('/accounts/callback/x?code=code&state=initiating-state')
        ->assertRedirect(route('accounts.index'))->assertSessionHas('error');
    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
})->with(['expired', 'different-user']);

test('picker selections cannot move oauth tokens to another workspace', function (string $platform) {
    [$user] = ownerActingIn();
    $intent = fakeAccountConnectionIntent($platform);
    $stash = $platform === 'meta'
        ? ['assets' => ['PAGE1' => [
            'pageId' => 'PAGE1', 'pageName' => 'Brand A', 'pageAccessToken' => 'private-page-token',
            'igUserId' => null, 'igUsername' => null, 'igAvatarUrl' => null,
        ]]]
        : [
            'person' => ['remoteAccountId' => 'PERSON1', 'handle' => 'member'],
            'organizations' => [], 'accessToken' => 'private-member-token',
        ];
    session()->put('accounts.'.$platform.'.connect', [...$stash, 'connection_intent' => $intent]);
    $user->forceFill(['current_workspace_id' => secondManagedWorkspace($user)->id])->save();

    $this->actingAs($user)->post(route('accounts.'.$platform.'.store'), [
        'selected' => $platform === 'meta' ? [['assetKey' => 'PAGE1', 'platform' => 'facebook']] : [['type' => 'person']],
    ])->assertRedirect(route('accounts.index'))->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
})->with(['meta', 'linkedin']);

test('bluesky callbacks cannot exchange tokens after switching workspaces', function () {
    [$user] = ownerActingIn();
    $state = str_repeat('a', 64);
    $connector = Mockery::mock(BlueskyOAuthConnector::class);
    $connector->shouldReceive('authorizationRedirect')->once()->andReturn([
        'url' => 'https://bsky.example/authorize', 'state' => $state, 'context' => ['issuer' => 'https://bsky.example'],
    ]);
    $connector->shouldNotReceive('callback');
    app()->instance(BlueskyOAuthConnector::class, $connector);
    $this->get(route('accounts.bluesky.oauth'))->assertRedirect('https://bsky.example/authorize');

    $user->forceFill(['current_workspace_id' => secondManagedWorkspace($user)->id])->save();
    $this->actingAs($user)->get(route('accounts.bluesky.oauth.callback', ['code' => 'code', 'state' => $state, 'iss' => 'https://bsky.example']))
        ->assertRedirect(route('accounts.index'))->assertSessionHas('error');

    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    expect(session('accounts.bluesky.oauth.'.$state))->toBeNull();
});
