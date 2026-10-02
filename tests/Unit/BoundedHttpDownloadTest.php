<?php

use App\Support\BoundedHttpDownload;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('rejects advertised oversized downloads before accepting the body and removes the temporary file', function () {
    $path = null;
    Http::fake(function (Request $request, array $options) use (&$path) {
        $path = $options['sink'];
        expect($options['decode_content'])->toBeFalse()
            ->and($request->hasHeader('Accept-Encoding', 'identity'))->toBeTrue();
        $options['on_headers'](new Response(200, ['Content-Length' => '9']));

        return Http::response('never downloaded');
    });

    expect(fn () => app(BoundedHttpDownload::class)->download(Http::timeout(5), 'https://example.com/a', 8, 'Too large.'))
        ->toThrow(RuntimeException::class, 'Too large.');
    expect($path)->toBeString()
        ->and(file_exists($path))->toBeFalse();
});

test('aborts chunked downloads while transferring and removes the partially written file', function () {
    $path = null;
    Http::fake(function (Request $request, array $options) use (&$path) {
        $path = $options['sink'];
        file_put_contents($path, 'first');
        $options['progress'](0, 9, 0, 0);

        return Http::response('never downloaded');
    });

    expect(fn () => app(BoundedHttpDownload::class)->download(Http::timeout(5), 'https://example.com/a', 8, 'Too large.'))
        ->toThrow(RuntimeException::class, 'Too large.');
    expect(file_exists($path))->toBeFalse();
});

test('rejects compressed responses so compressed bytes cannot bypass the transfer ceiling', function () {
    Http::fake(function (Request $request, array $options) {
        $options['on_headers'](new Response(200, ['Content-Encoding' => 'gzip']));

        return Http::response('never downloaded');
    });

    expect(fn () => app(BoundedHttpDownload::class)->download(Http::timeout(5), 'https://example.com/a', 8, 'Too large.'))
        ->toThrow(RuntimeException::class, 'unsupported compressed response');
});

test('checks the actual downloaded size even when advertised lengths are missing', function () {
    $path = null;
    Http::fake(function (Request $request, array $options) use (&$path) {
        $path = $options['sink'];

        return Http::response('123456789');
    });

    expect(fn () => app(BoundedHttpDownload::class)->download(Http::timeout(5), 'https://example.com/a', 8, 'Too large.'))
        ->toThrow(RuntimeException::class, 'Too large.');
    expect(file_exists($path))->toBeFalse();
});

test('returns a file path and size for a bounded successful download', function () {
    Http::fake(['https://example.com/a' => Http::response('payload')]);
    $download = app(BoundedHttpDownload::class)->download(Http::timeout(5), 'https://example.com/a', 8, 'Too large.');

    try {
        expect($download['size'])->toBe(7)
            ->and(file_get_contents($download['path']))->toBe('payload');
    } finally {
        unlink($download['path']);
    }
});
