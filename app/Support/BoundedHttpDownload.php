<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class BoundedHttpDownload
{
    /**
     * Download into a temporary file with an in-flight ceiling. The caller owns
     * the returned file and must unlink it after reading or storing the media.
     *
     * @return array{path: string, size: int}
     */
    public function download(PendingRequest $request, string $url, int $limit, string $tooLarge): array
    {
        $path = tempnam(sys_get_temp_dir(), 'media-download-');

        if ($path === false) {
            throw new RuntimeException('Could not buffer the media download.');
        }

        $rejection = null;

        try {
            $response = $request->withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions([
                    'sink' => $path,
                    'decode_content' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($limit, $tooLarge, &$rejection): void {
                        if ((float) $response->getHeaderLine('Content-Length') > $limit) {
                            $rejection = $tooLarge;
                            throw new RuntimeException($rejection);
                        }

                        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                        if ($encoding !== '' && $encoding !== 'identity') {
                            $rejection = 'The media host returned an unsupported compressed response.';
                            throw new RuntimeException($rejection);
                        }
                    },
                    'progress' => static function (float $total, float $downloaded) use ($limit, $tooLarge, &$rejection): void {
                        if ($downloaded > $limit) {
                            $rejection = $tooLarge;
                            throw new RuntimeException($rejection);
                        }
                    },
                ])->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('Could not download the media (HTTP '.$response->status().').');
            }

            $response->toPsrResponse()->getBody()->close();
            clearstatcache(true, $path);
            $size = filesize($path);

            if ($size === false) {
                throw new RuntimeException('Could not read the media download.');
            }

            if ($size > $limit) {
                throw new RuntimeException($tooLarge);
            }

            return ['path' => $path, 'size' => $size];
        } catch (Throwable $exception) {
            @unlink($path);

            if ($rejection !== null) {
                throw new RuntimeException($rejection, previous: $exception);
            }

            throw $exception;
        }
    }
}
