<?php

declare(strict_types=1);

namespace App\Services\Creator;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CreatorOutputDownloader
{
    /**
     * @return array{bytes: string, mime: string} */
    public function download(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $providerHost = $host === 'fal.media' || preg_match('/^v[0-9]+[a-z]?\.fal\.media$/', $host) === 1;
        $providerBucket = $host === 'storage.googleapis.com' && str_starts_with($path, '/falserverless/');
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || (! $providerHost && ! $providerBucket)
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new CreatorProviderException('unsafe_output_url', 'The provider returned an unsupported image host. The generated request has not been resubmitted.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
        } catch (ConnectionException) {
            throw new CreatorProviderException('output_unreachable', 'The generated image could not be downloaded. Check the same generation again later.');
        }
        if (! $response->successful() || (int) $response->header('Content-Length') > 8388608) {
            throw new CreatorProviderException('output_unavailable', 'The generated image is unavailable or exceeds the 8 MiB import limit.');
        }
        $resource = $response->resource();
        $bytes = stream_get_contents($resource, 8388609);
        fclose($resource);
        if (! is_string($bytes) || strlen($bytes) > 8388608) {
            throw new CreatorProviderException('output_too_large', 'The generated image exceeds the 8 MiB import limit.');
        }
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new CreatorProviderException('invalid_output_image', 'The model result is not a supported raster image.');
        }

        return ['bytes' => $bytes, 'mime' => $info['mime']];
    }
}
