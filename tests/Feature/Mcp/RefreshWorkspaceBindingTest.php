<?php

use App\Models\McpGrantWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

function approvedMcpCode(User $user, Workspace $workspace, Client $client, ?string $postedClientId = null): string
{
    $query = http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => 'http://localhost/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'workspace-binding-test',
    ]);

    test()->actingAs($user)->get("/oauth/authorize?{$query}")->assertOk();
    $approval = test()->post('/oauth/authorize', [
        'client_id' => $postedClientId ?? $client->id,
        'workspace_id' => $workspace->id,
        'auth_token' => session('authToken'),
    ])->assertRedirect();
    parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $parameters);

    return $parameters['code'];
}

/** @return array{user: User, workspace: Workspace, client: Client, token_id: string, refresh_token: string} */
function issuedMcpRefreshGrant(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Workspace refresh test', ['http://localhost/callback'], confidential: true, user: $user,
    );
    $code = approvedMcpCode($user, $workspace, $client);
    $exchange = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
        'redirect_uri' => 'http://localhost/callback',
        'code' => $code,
    ])->assertOk();
    $tokenId = McpGrantWorkspace::query()->where('authorization_code_hash', hash('sha256', $code))->value('access_token_id');
    expect($tokenId)->toBeString();

    return [
        'user' => $user,
        'workspace' => $workspace,
        'client' => $client,
        'token_id' => $tokenId,
        'refresh_token' => $exchange->json('refresh_token'),
    ];
}

/** @param array{client: Client, refresh_token: string} $grant */
function refreshMcpGrant(array $grant): void
{
    test()->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $grant['client']->id,
        'client_secret' => $grant['client']->plainSecret,
        'refresh_token' => $grant['refresh_token'],
    ])->assertOk();
}

test('refreshing an MCP grant preserves its workspace instead of consuming a pending consent', function () {
    $grant = issuedMcpRefreshGrant();
    $otherWorkspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $grant['user']->id]);
    $pending = McpGrantWorkspace::create([
        'user_id' => $grant['user']->id,
        'client_id' => $grant['client']->id,
        'workspace_id' => $otherWorkspace->id,
        'authorization_code_hash' => hash('sha256', 'another-pending-consent'),
    ]);

    refreshMcpGrant($grant);

    $newToken = Passport::token()->newQuery()->where('user_id', $grant['user']->id)->where('revoked', false)->sole();
    expect($newToken->id)->not->toBe($grant['token_id']);
    expect(McpGrantWorkspace::where('access_token_id', $newToken->id)->value('workspace_id'))->toBe($grant['workspace']->id);
    expect($pending->fresh()->access_token_id)->toBeNull();
});

test('refreshing an unbound token cannot acquire a pending workspace selection', function () {
    $grant = issuedMcpRefreshGrant();
    McpGrantWorkspace::where('access_token_id', $grant['token_id'])->delete();
    $pending = McpGrantWorkspace::create([
        'user_id' => $grant['user']->id,
        'client_id' => $grant['client']->id,
        'workspace_id' => $grant['workspace']->id,
        'authorization_code_hash' => hash('sha256', 'unrelated-consent'),
    ]);

    refreshMcpGrant($grant);

    $newToken = Passport::token()->newQuery()->where('user_id', $grant['user']->id)->where('revoked', false)->sole();
    expect(McpGrantWorkspace::where('access_token_id', $newToken->id)->exists())->toBeFalse();
    expect($pending->fresh()->access_token_id)->toBeNull();
});

test('refreshing after membership removal cannot regain workspace access', function () {
    $grant = issuedMcpRefreshGrant();
    WorkspaceMembership::query()->where('workspace_id', $grant['workspace']->id)->where('user_id', $grant['user']->id)->delete();

    refreshMcpGrant($grant);

    $newToken = Passport::token()->newQuery()->where('user_id', $grant['user']->id)->where('revoked', false)->sole();
    expect(McpGrantWorkspace::where('access_token_id', $newToken->id)->exists())->toBeFalse();
});

test('separate pending consents for the same client keep the exact selected workspace', function () {
    $user = User::factory()->create();
    $workspaces = [Workspace::factory()->create(), Workspace::factory()->create()];
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Parallel workspace consent', ['http://localhost/callback'], confidential: true, user: $user,
    );

    $codes = [];
    foreach ($workspaces as $workspace) {
        WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
        $codes[] = approvedMcpCode($user, $workspace, $client);
    }

    foreach ([1, 0] as $index) {
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri' => 'http://localhost/callback',
            'code' => $codes[$index],
        ])->assertOk();

        $binding = McpGrantWorkspace::where('authorization_code_hash', hash('sha256', $codes[$index]))->sole();
        expect($binding->access_token_id)->not->toBeNull();
        expect($binding->workspace_id)->toBe($workspaces[$index]->id);
    }
});

test('the consent client comes from the validated authorization session', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Validated consent client', ['http://localhost/callback'], confidential: true, user: $user,
    );

    $code = approvedMcpCode($user, $workspace, $client, 'tampered-client');

    expect(McpGrantWorkspace::where('authorization_code_hash', hash('sha256', $code))->value('client_id'))->toBe($client->id);
});

test('a valid existing token still requires workspace consent when reconnecting', function () {
    $grant = issuedMcpRefreshGrant();
    $otherWorkspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $grant['user']->id]);

    $code = approvedMcpCode($grant['user'], $otherWorkspace, $grant['client']);
    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $grant['client']->id,
        'client_secret' => $grant['client']->plainSecret,
        'redirect_uri' => 'http://localhost/callback',
        'code' => $code,
    ])->assertOk();

    $binding = McpGrantWorkspace::where('authorization_code_hash', hash('sha256', $code))->sole();
    expect($binding->workspace_id)->toBe($otherWorkspace->id);
    expect($binding->access_token_id)->not->toBeNull();
    expect(McpGrantWorkspace::where('access_token_id', $grant['token_id'])->value('workspace_id'))->toBe($grant['workspace']->id);
});

test('silent reconnect cannot bypass workspace selection', function () {
    $grant = issuedMcpRefreshGrant();
    $query = http_build_query([
        'client_id' => $grant['client']->id,
        'redirect_uri' => 'http://localhost/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'silent-workspace-test',
        'prompt' => 'none',
    ]);

    $response = $this->get("/oauth/authorize?{$query}")->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $parameters);

    expect($parameters['error'])->toBe('consent_required');
    expect($parameters)->not->toHaveKey('code');
    expect(McpGrantWorkspace::whereNull('access_token_id')->exists())->toBeFalse();
});
