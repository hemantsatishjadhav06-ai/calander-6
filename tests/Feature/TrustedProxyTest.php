<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

afterEach(function (): void {
    TrustProxies::flushState();
});

test('app.trusted_proxies config wires the trusted proxy via the provider', function (): void {
    config(['app.trusted_proxies' => '198.51.100.4']);

    Closure::bind(
        fn () => $this->configureTrustedProxies(),
        new AppServiceProvider($this->app),
        AppServiceProvider::class,
    )();

    $request = Request::create('http://app.test/', 'GET');
    $request->server->set('REMOTE_ADDR', '198.51.100.4');
    $request->headers->set('X-Forwarded-Proto', 'https');

    (new TrustProxies)->handle($request, fn (): null => null);

    expect($request->isSecure())->toBeTrue();
});

/**
 * `/dashboard` rather than `/`: these two need a route that redirects a guest
 * so the generated Location header reveals the scheme the app believes it is
 * on. `/` used to be that route, but it now serves the public home page to
 * guests. The auth redirect exercises exactly the same URL generation.
 */
test('honors X-Forwarded-Proto from a trusted proxy, generating https redirects', function (): void {
    TrustProxies::at('*');

    $response = $this->get('/dashboard', ['X-Forwarded-Proto' => 'https']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://');
});

test('ignores X-Forwarded-Proto when no proxy is trusted', function (): void {
    $response = $this->get('/dashboard', ['X-Forwarded-Proto' => 'https']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('http://');
});
