<?php

use App\Services\ConnectedAccounts\BlueskyConnector;
use App\Support\PublicHttpUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function urlGuardWithAddresses(array $ips): PublicHttpUrl
{
    return new class($ips) extends PublicHttpUrl
    {
        public function __construct(private readonly array $ips) {}

        protected function resolveAddresses(string $host): array
        {
            return $this->ips;
        }
    };
}

test('rejects nonpublic service URLs before making a request', function (string $url) {
    Http::fake();

    expect(fn () => (new PublicHttpUrl)->options($url))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
})->with([
    'http' => 'http://example.com',
    'localhost' => 'https://localhost',
    'trailing dot localhost' => 'https://localhost.',
    'localhost subdomain' => 'https://app.localhost',
    'internal domain' => 'https://app.internal',
    'loopback' => 'https://127.0.0.1',
    'private' => 'https://10.0.0.1',
    'metadata' => 'https://169.254.169.254',
    'carrier nat' => 'https://100.64.0.1',
    'mapped loopback' => 'https://[::ffff:127.0.0.1]',
    'ipv6 loopback' => 'https://[::1]',
    'userinfo' => 'https://user:password@example.com',
]);

test('rejects private mixed and unresolved DNS answers', function (array $ips) {
    expect(fn () => urlGuardWithAddresses($ips)->options('https://pds.example'))
        ->toThrow(RuntimeException::class);
})->with([
    'private ipv4' => [['10.0.0.10']],
    'private ipv6' => [['fd00::1']],
    'mixed addresses' => [['93.184.216.34', '127.0.0.1']],
    'unresolved' => [[]],
]);

test('pins public DNS and disables redirects while preserving service ports', function () {
    expect(urlGuardWithAddresses(['93.184.216.34'])->options('https://pds.example:8443/xrpc'))
        ->toBe([
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => ['pds.example:8443:93.184.216.34']],
        ]);

    expect(urlGuardWithAddresses(['2606:4700::1111'])->options('https://pds.example/xrpc'))
        ->toBe([
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => ['pds.example:443:[2606:4700::1111]']],
        ]);
});

test('validates DNS again when a saved Bluesky service is used', function () {
    $guard = new class extends PublicHttpUrl
    {
        private int $lookups = 0;

        protected function resolveAddresses(string $host): array
        {
            return ++$this->lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        }
    };
    app()->instance(PublicHttpUrl::class, $guard);
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'])->toBeFalse()
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['pds.example:443:93.184.216.34']);

        return Http::response([]);
    });

    $connector = app(BlueskyConnector::class);
    $connector->request('https://pds.example')->get('https://pds.example/xrpc');

    expect(fn () => $connector->request('https://pds.example'))
        ->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});
