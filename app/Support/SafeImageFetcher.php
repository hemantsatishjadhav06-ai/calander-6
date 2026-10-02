<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Concerns\PinsCurlResolution;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Downloads an image from a user-supplied URL with SSRF protections: scheme
 * allow-list, host/DNS private-range rejection, IP pinning, size cap, and
 * content-type + magic-byte validation.
 *
 * Redirect safety: redirects are disabled (`allow_redirects: false`) so a public
 * URL cannot 302 to a private host that bypasses the pre-fetch check.
 *
 * DNS-rebinding safety: the hostname is resolved once and validated, then curl is
 * pinned to that exact IP via CURLOPT_RESOLVE. This closes the time-of-check /
 * time-of-use gap where an attacker-controlled short-TTL record could return a
 * public IP for our check and a private IP for curl's own connect-time lookup. TLS
 * SNI and certificate verification still use the original hostname, so HTTPS is
 * unaffected.
 */
class SafeImageFetcher
{
    use PinsCurlResolution;

    private const int MAX_BYTES = 8 * 1024 * 1024; // 8 MiB

    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly BoundedHttpDownload $download = new BoundedHttpDownload,
    ) {}

    /**
     * Fetch an image from the given URL after SSRF validation.
     *
     * @return array{bytes: string, mime: string}
     *
     * @throws RuntimeException if the URL is blocked or the response is not a valid image.
     */
    public function fetch(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Image URL must use http or https.');
        }

        $rawHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($rawHost) || $rawHost === '') {
            throw new RuntimeException('Image URL has no host.');
        }

        $host = strtolower(trim($rawHost, '[]'));
        $ips = $this->resolveValidatedIps($host);

        $request = $this->http
            ->timeout(10)
            ->connectTimeout(5)
            ->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => $this->pinnedResolution($host, (string) $scheme, $url, $ips),
            ]);
        try {
            $download = $this->download->download($request, $url, self::MAX_BYTES, 'Image exceeds the 8 MiB limit.');
        } catch (ConnectionException) {
            throw new RuntimeException('Could not connect to the image host.');
        }

        try {
            $bytes = file_get_contents($download['path']);
            if ($bytes === false) {
                throw new RuntimeException('Could not read the downloaded image.');
            }

            $info = @getimagesizefromstring($bytes);
            if ($info === false || ! in_array($info['mime'], self::ALLOWED_MIME, true)) {
                throw new RuntimeException('URL did not return a supported image (jpeg, png, webp, gif).');
            }

            return ['bytes' => $bytes, 'mime' => (string) $info['mime']];
        } finally {
            @unlink($download['path']);
        }
    }

    /**
     * Resolve a hostname (or accept an IP literal) and reject if any resolved
     * address is private or reserved.
     *
     * @return non-empty-list<string>
     *
     * @throws RuntimeException
     */
    private function resolveValidatedIps(string $host): array
    {
        $canonicalHost = rtrim($host, '.');
        if ($canonicalHost === 'localhost' || str_ends_with($canonicalHost, '.localhost') || str_ends_with($canonicalHost, '.local') || str_ends_with($canonicalHost, '.internal')) {
            throw new RuntimeException('That host is not allowed.');
        }

        // If the host is already an IP literal, validate it directly. Otherwise
        // resolve A (IPv4) and AAAA (IPv6) records.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_merge(gethostbynamel($host) ?: [], $this->resolveAaaa($host));

        if ($ips === []) {
            throw new RuntimeException('That host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new RuntimeException('That host resolves to a private or reserved address.');
            }
        }

        return $ips;
    }

    /**
     * @return list<string>
     */
    private function resolveAaaa(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $r): ?string => $r['ipv6'] ?? null,
            $records,
        )));
    }
}
