<?php

use App\Services\Atproto\DPoP;
use App\Services\ConnectedAccounts\BlueskyConnector;
use App\Services\ConnectedAccounts\BlueskyOAuthConnector;
use App\Support\PublicHttpUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePublicHttpUrl;

beforeEach(function () {
    app()->instance(PublicHttpUrl::class, new FakePublicHttpUrl);
});

function canonicalBlueskyDocument(string $did, string $pds): array
{
    return [
        'id' => $did,
        'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $pds]],
    ];
}

function canonicalBlueskyMetadata(string $issuer, ?string $tokenEndpoint = null): array
{
    return [
        'issuer' => $issuer,
        'authorization_endpoint' => $issuer.'/oauth/authorize',
        'token_endpoint' => $tokenEndpoint ?? $issuer.'/oauth/token',
        'pushed_authorization_request_endpoint' => $issuer.'/oauth/par',
    ];
}

function canonicalBlueskyContext(string $issuer, ?string $expectedDid = null): array
{
    return [
        'expected_did' => $expectedDid,
        'pds' => $issuer,
        'issuer' => $issuer,
        'token_endpoint' => $issuer.'/oauth/token',
        'client_id' => 'http://localhost/?scope=atproto',
        'redirect_uri' => 'http://127.0.0.1/callback',
        'code_verifier' => 'verifier',
        'dpop_private_jwk' => app(DPoP::class)->generateKey(),
    ];
}

test('a custom server cannot receive app credentials or start oauth for another did', function (bool $oauth) {
    Http::fake([
        '*xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:abc']),
        'https://plc.directory/did:plc:abc' => Http::response(canonicalBlueskyDocument('did:plc:abc', 'https://canonical.example')),
    ]);

    expect(fn () => $oauth
        ? app(BlueskyOAuthConnector::class)->authorizationRedirect('victim.bsky.social', 'http://localhost/?scope=atproto', 'http://127.0.0.1/callback', 'https://evil.example')
        : app(BlueskyConnector::class)->connect('victim.bsky.social', 'app-password', 'https://evil.example'))
        ->toThrow(RuntimeException::class, 'does not match the account identity');

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://evil.example'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with(['app password' => false, 'oauth' => true]);

test('a custom server session cannot substitute another did', function () {
    Http::fake([
        'https://plc.directory/did:plc:abc' => Http::response(canonicalBlueskyDocument('did:plc:abc', 'https://custom.example:8443')),
        'https://custom.example:8443/xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:def', 'accessJwt' => 'token',
        ]),
    ]);

    expect(fn () => app(BlueskyConnector::class)->connect('did:plc:abc', 'password', 'https://custom.example:8443'))
        ->toThrow(RuntimeException::class, 'different account');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'getProfile'));
});

test('canonical did documents must identify the requested account and its atproto service', function (array $document) {
    Http::fake(['https://plc.directory/did:plc:abc' => Http::response($document)]);

    expect(fn () => app(BlueskyConnector::class)->canonicalPdsForDid('did:plc:abc'))
        ->toThrow(RuntimeException::class);
})->with([
    'missing document identity' => [['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://evil.example']]]],
    'different document identity' => [canonicalBlueskyDocument('did:plc:def', 'https://evil.example')],
    'different service identity' => [['id' => 'did:plc:abc', 'service' => [['id' => '#unrelated', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://evil.example']]]],
    'internal canonical service' => [canonicalBlueskyDocument('did:plc:abc', 'https://127.0.0.1')],
]);

test('did web identities resolve their canonical document and public custom port', function (string $did, string $documentUrl) {
    Http::fake([$documentUrl => Http::response(canonicalBlueskyDocument($did, 'https://custom.example:8443/'))]);

    expect(app(BlueskyConnector::class)->resolvePds($did, 'https://custom.example:8443/'))->toBe('https://custom.example:8443');
    Http::assertSent(fn (Request $request): bool => $request->url() === $documentUrl);
})->with([
    'root' => ['did:web:identity.example', 'https://identity.example/.well-known/did.json'],
    'path and port' => ['did:web:identity.example%3A8443:users:ada', 'https://identity.example:8443/users/ada/did.json'],
]);

test('invalid or internal did document locations cannot cause requests', function (string $did) {
    expect(fn () => app(BlueskyConnector::class)->canonicalPdsForDid($did))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    'plc path injection' => 'did:plc:abc/../../secret',
    'unsupported method' => 'did:key:abc',
    'web private address' => 'did:web:127.0.0.1',
    'web userinfo' => 'did:web:evil.example%40identity.example',
    'web path traversal' => 'did:web:identity.example:%2e%2e:secret',
    'web encoded slash' => 'did:web:identity.example:users%2Fada',
]);

test('oauth cannot claim a did whose canonical server authorizes a different issuer', function (?string $expectedDid) {
    Http::fake([
        'https://evil.example/oauth/token' => Http::response(['sub' => 'did:plc:abc', 'access_token' => 'fabricated-token']),
        'https://plc.directory/did:plc:abc' => Http::response(canonicalBlueskyDocument('did:plc:abc', 'https://canonical.example')),
        'https://canonical.example/.well-known/oauth-protected-resource' => Http::response(['authorization_servers' => ['https://trusted.example']]),
        'https://trusted.example/.well-known/oauth-authorization-server' => Http::response(canonicalBlueskyMetadata('https://trusted.example')),
    ]);

    expect(fn () => app(BlueskyOAuthConnector::class)->callback('attacker-code', 'https://evil.example', canonicalBlueskyContext('https://evil.example', $expectedDid)))
        ->toThrow(RuntimeException::class, 'does not match the account identity');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'getProfile'));
})->with(['handle omitted' => null, 'requested victim did' => 'did:plc:abc']);

test('oauth rejects a token endpoint that differs from canonical authorization metadata', function () {
    Http::fake([
        'https://trusted.example/oauth/token' => Http::response(['sub' => 'did:plc:abc', 'access_token' => 'token']),
        'https://plc.directory/did:plc:abc' => Http::response(canonicalBlueskyDocument('did:plc:abc', 'https://canonical.example')),
        'https://canonical.example/.well-known/oauth-protected-resource' => Http::response(['authorization_servers' => ['https://trusted.example']]),
        'https://trusted.example/.well-known/oauth-authorization-server' => Http::response(canonicalBlueskyMetadata('https://trusted.example', 'https://trusted.example/real-token')),
    ]);

    expect(fn () => app(BlueskyOAuthConnector::class)->callback('code', 'https://trusted.example', canonicalBlueskyContext('https://trusted.example')))
        ->toThrow(RuntimeException::class, 'does not match the account identity');
});

test('oauth saves a custom canonical pds when its authorized issuer proves the same did', function () {
    Http::fake([
        'https://trusted.example/oauth/token' => Http::response(['sub' => 'did:web:identity.example', 'access_token' => 'token']),
        'https://identity.example/.well-known/did.json' => Http::response(canonicalBlueskyDocument('did:web:identity.example', 'https://custom.example:8443')),
        'https://custom.example:8443/.well-known/oauth-protected-resource' => Http::response(['authorization_servers' => ['https://trusted.example']]),
        'https://trusted.example/.well-known/oauth-authorization-server' => Http::response(canonicalBlueskyMetadata('https://trusted.example')),
        '*xrpc/app.bsky.actor.getProfile*' => Http::response(['handle' => 'ada.example']),
    ]);

    $data = app(BlueskyOAuthConnector::class)->callback('code', 'https://trusted.example', canonicalBlueskyContext('https://trusted.example', 'did:web:identity.example'));

    expect($data->remoteAccountId)->toBe('did:web:identity.example')
        ->and($data->session['pds'])->toBe('https://custom.example:8443')
        ->and($data->session['issuer'])->toBe('https://trusted.example');
});
