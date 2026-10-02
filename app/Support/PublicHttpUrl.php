<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\PinsCurlResolution;
use RuntimeException;

class PublicHttpUrl
{
    use PinsCurlResolution;

    /**
     * Validate and pin each outbound connection, including requests to previously
     * saved endpoints, so a later DNS change cannot reach an internal service.
     *
     * @return array{allow_redirects: false, proxy: string, curl: array<int, list<string>>}
     */
    public function options(string $url): array
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Safe service connections require the PHP cURL extension.');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            throw new RuntimeException('The service URL must use https.');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $canonicalHost = rtrim($host, '.');

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])
            || $canonicalHost === 'localhost' || str_ends_with($canonicalHost, '.localhost')
            || str_ends_with($canonicalHost, '.local') || str_ends_with($canonicalHost, '.internal')) {
            throw new RuntimeException('That service URL is not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveAddresses($host);

        if ($ips === []) {
            throw new RuntimeException('The service host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new RuntimeException('The service host resolves to a private or reserved address.');
            }
        }

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => $this->pinnedResolution($host, 'https', $url, $ips),
        ];
    }

    /** @return list<string> */
    protected function resolveAddresses(string $host): array
    {
        $ipv4 = @gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        $ipv6 = array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ipv6'] ?? null,
            $records,
        )));

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
