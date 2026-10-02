<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Support\PublicHttpUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use Throwable;

class BlueskyConnector
{
    private const string DEFAULT_PDS = 'https://bsky.social';

    private const string APPVIEW = 'https://public.api.bsky.app';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicHttpUrl $urls,
    ) {}

    public function connect(string $identifier, string $appPassword, ?string $pdsUrl = null): ConnectedAccountData
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $expectedDid = null;
        $pds = $this->resolvePdsAndDid($identifier, $pdsUrl, $expectedDid);

        // Guard the resolved endpoint too (not just a user override) before sending
        // credentials to it — the DID-document endpoint is attacker-influenceable.
        $this->assertSafeServiceUrl($pds);

        try {
            $sessionResponse = $this->request($pds)
                ->acceptJson()
                ->post($pds.'/xrpc/com.atproto.server.createSession', [
                    'identifier' => $identifier,
                    'password' => $appPassword,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach the Bluesky server. Please try again.');
        }

        if (! $sessionResponse->successful()) {
            throw new RuntimeException('Bluesky rejected those credentials. Check the identifier and app password.');
        }

        $session = $sessionResponse->json();
        $did = $session['did'] ?? null;

        if (! is_string($did) || $did === '') {
            throw new RuntimeException('Bluesky did not return an account identity.');
        }

        if ($expectedDid !== null && $did !== $expectedDid) {
            throw new RuntimeException('Bluesky returned a different account than the one requested.');
        }

        /** @var array<string, mixed> $profile */
        $profile = [];

        try {
            $profileResponse = $this->request($pds)
                ->withToken((string) $session['accessJwt'])
                ->acceptJson()
                ->get($pds.'/xrpc/app.bsky.actor.getProfile', ['actor' => $did]);
            $profile = $profileResponse->successful() ? (array) $profileResponse->json() : [];
        } catch (ConnectionException) {
            // Session creation succeeded; optional profile information can be fetched later.
        }

        $handle = $profile['handle'] ?? $session['handle'] ?? $did;

        return new ConnectedAccountData(
            platform: Platform::Bluesky,
            remoteAccountId: $did,
            handle: '@'.$handle,
            displayName: $profile['displayName'] ?? null,
            avatarUrl: $profile['avatar'] ?? null,
            authMethod: 'app_password',
            appPassword: $appPassword,
            session: [
                'accessJwt' => $session['accessJwt'] ?? null,
                'refreshJwt' => $session['refreshJwt'] ?? null,
                'pds' => $pds,
            ],
        );
    }

    public function resolveDid(string $identifier): ?string
    {
        $identifier = $this->normalizeIdentifier($identifier);

        if (str_starts_with($identifier, 'did:')) {
            return $identifier;
        }

        return $this->resolveHandleToDid($identifier);
    }

    public function resolvePds(string $identifier, ?string $override): string
    {
        $did = null;

        return $this->resolvePdsAndDid($identifier, $override, $did);
    }

    public function resolvePdsAndDid(string $identifier, ?string $override, ?string &$did): string
    {
        $identifier = $this->normalizeIdentifier($identifier);

        if ($override !== null && trim($override) !== '') {
            $pds = rtrim(trim($override), '/');
            $this->assertSafeServiceUrl($pds);

            try {
                $did = $this->resolveDid($identifier);
            } catch (Throwable) {
                $did = null;
            }

            // bsky.social is the trusted sign-in broker for its hosted PDSs.
            // A custom server must be authorized by the account's canonical DID.
            if ($pds !== self::DEFAULT_PDS && ($did === null || $this->canonicalPdsForDid($did) !== $pds)) {
                throw new RuntimeException('That Bluesky server does not match the account identity.');
            }

            return $pds;
        }

        try {
            $did = $this->resolveDid($identifier);
            $pds = $did !== null ? $this->canonicalPdsForDid($did) : null;

            return $pds ?? self::DEFAULT_PDS;
        } catch (Throwable) {
            $did = null;

            return self::DEFAULT_PDS;
        }
    }

    /**
     * @throws RuntimeException when the endpoint is not a safe public https URL
     */
    public function assertSafeServiceUrl(string $url): void
    {
        $this->urls->options($url);
    }

    public function request(string $url): PendingRequest
    {
        return $this->http->withOptions($this->urls->options($url))
            ->timeout(10)->connectTimeout(5);
    }

    private function resolveHandleToDid(string $handle): ?string
    {
        $candidates = [self::APPVIEW];

        $parts = explode('.', $handle);
        if (count($parts) > 2) {
            $candidates[] = 'https://'.implode('.', array_slice($parts, 1));
        }
        $candidates[] = 'https://'.$handle;

        foreach ($candidates as $service) {
            try {
                $this->assertSafeServiceUrl($service);
            } catch (RuntimeException) {
                continue;
            }

            $response = $this->request($service)
                ->timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->get($service.'/xrpc/com.atproto.identity.resolveHandle', ['handle' => $handle]);

            $did = $response->successful() ? $response->json('did') : null;

            if (is_string($did) && str_starts_with($did, 'did:')) {
                return $did;
            }
        }

        return null;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return ltrim(trim($identifier), '@');
    }

    public function canonicalPdsForDid(string $did): string
    {
        $documentUrl = $this->didDocumentUrl($did);

        try {
            $response = $this->request($documentUrl)->acceptJson()->get($documentUrl);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not verify the Bluesky account identity. Please try again.');
        }

        if (! $response->successful() || $response->json('id') !== $did) {
            throw new RuntimeException('Could not verify the Bluesky account identity.');
        }

        $services = $response->json('service', []);

        if (is_array($services)) {
            foreach ($services as $service) {
                if (! is_array($service)
                    || ! in_array($service['id'] ?? null, ['#atproto_pds', $did.'#atproto_pds'], true)
                    || ($service['type'] ?? null) !== 'AtprotoPersonalDataServer'
                    || ! is_string($service['serviceEndpoint'] ?? null)) {
                    continue;
                }

                $endpoint = rtrim($service['serviceEndpoint'], '/');
                $this->assertSafeServiceUrl($endpoint);

                return $endpoint;
            }
        }

        throw new RuntimeException('The Bluesky account identity does not identify a server.');
    }

    private function didDocumentUrl(string $did): string
    {
        if (preg_match('/^did:plc:[a-z2-7]+$/D', $did) === 1) {
            return 'https://plc.directory/'.$did;
        }

        if (str_starts_with($did, 'did:web:')) {
            $parts = explode(':', substr($did, strlen('did:web:')));
            $host = rawurldecode(array_shift($parts));

            if ($host === '' || preg_match('/[\s\/?#@\\\\]/', $host) === 1) {
                throw new RuntimeException('The Bluesky account identity is invalid.');
            }

            $path = '';
            foreach ($parts as $part) {
                $segment = rawurldecode($part);
                if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x20\/?#\\\\]/', $segment) === 1) {
                    throw new RuntimeException('The Bluesky account identity is invalid.');
                }

                $path .= '/'.rawurlencode($segment);
            }

            return 'https://'.$host.($path === '' ? '/.well-known' : $path).'/did.json';
        }

        throw new RuntimeException('The Bluesky account identity is invalid.');
    }
}
