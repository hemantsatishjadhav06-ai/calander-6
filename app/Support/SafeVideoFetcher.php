<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Platform;
use App\Support\Concerns\PinsCurlResolution;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Downloads an MP4 from a user-supplied URL with the same SSRF protections as
 * SafeImageFetcher (scheme allow-list, private-range rejection, IP pinning, no
 * redirects), but capped at the video ceiling and validated by the ISO-BMFF
 * `ftyp` box rather than by image headers.
 */
class SafeVideoFetcher
{
    use PinsCurlResolution;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly BoundedHttpDownload $download = new BoundedHttpDownload,
    ) {}

    /**
     * The caller owns the returned temporary file and must unlink it.
     *
     * @return array{path: string, size: int, mime: string}
     *
     * @throws RuntimeException if the URL is blocked or the response is not an MP4.
     */
    public function fetch(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Video URL must use http or https.');
        }

        $rawHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($rawHost) || $rawHost === '') {
            throw new RuntimeException('Video URL has no host.');
        }

        $host = strtolower(trim($rawHost, '[]'));
        $ips = $this->resolveValidatedIps($host);
        $ceiling = (int) config('media.max_video_bytes', Platform::maxVideoBytesCeiling());

        try {
            $request = $this->http
                ->timeout(20)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'curl' => $this->pinnedResolution($host, (string) $scheme, $url, $ips),
                ]);
            $download = $this->download->download($request, $url, $ceiling, 'Clip exceeds the maximum allowed video size.');
        } catch (ConnectionException) {
            throw new RuntimeException('Could not connect to the video host.');
        }

        $source = fopen($download['path'], 'rb');
        if ($source === false) {
            @unlink($download['path']);
            throw new RuntimeException('Could not read the downloaded clip.');
        }

        try {
            $header = fread($source, 8);
            if ($header === false || substr($header, 4, 4) !== 'ftyp') {
                @unlink($download['path']);
                throw new RuntimeException('The downloaded clip is not a valid MP4 video.');
            }
        } finally {
            fclose($source);
        }

        return [...$download, 'mime' => 'video/mp4'];
    }

    // --- SSRF helpers: copied verbatim from SafeImageFetcher, except
    // pinnedResolution(), shared via the PinsCurlResolution trait -----------

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
