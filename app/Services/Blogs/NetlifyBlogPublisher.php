<?php

declare(strict_types=1);

namespace App\Services\Blogs;

use App\Models\BlogDraft;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class NetlifyBlogPublisher
{
    private const string API = 'https://api.netlify.com/api/v1';

    /**
     * @param  array<string, string|null>  $destination
     * @param  callable(string, string, string, bool, callable(callable(): void): void): void  $authorizePromotion
     */
    public function publish(BlogDraft $draft, array $destination, callable $authorizePromotion): void
    {
        $siteId = (string) $destination['netlify_site_id'];
        $website = rtrim((string) $destination['website_url'], '/');
        /** @var array<string, string> $sites */
        $sites = config('blogs.sites', []);
        if (! isset($sites[$siteId]) || $sites[$siteId] !== $website || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $draft->slug)) {
            throw new BlogPublicationException('The website destination or article URL is not supported.');
        }

        $site = $this->json('GET', '/sites/'.$siteId);
        if (($site['id'] ?? null) !== $siteId || rtrim((string) ($site['ssl_url'] ?? ''), '/') !== $website
            || ! preg_match('/^[a-z0-9-]+$/', (string) ($site['name'] ?? ''))) {
            throw new BlogPublicationException('Netlify could not verify this website destination.');
        }
        $base = $site['published_deploy'] ?? null;
        if (! is_array($base) || ! $this->validDeploy($base, $siteId) || ($base['state'] ?? null) !== 'ready') {
            throw new BlogPublicationException('The website does not have a ready production deployment.');
        }
        $baseId = (string) $base['id'];
        $this->assertStatic($siteId, $baseId);
        $manifest = $this->manifest($baseId);
        $path = '/blog/'.$draft->slug.'/index.html';
        $url = $website.'/blog/'.$draft->slug.'/';
        if (isset($manifest[$path]) && $draft->published_url !== $url
            && ! ($draft->publication_deploy_id === $baseId && $draft->publication_url === $url)) {
            throw new BlogPublicationException('That article URL already exists on the website. Choose another slug and request fresh approval.');
        }
        $css = (string) file_get_contents(resource_path('css/blog-publication.css'));
        $cssPath = '/blog/sm-manager-'.substr(hash('sha256', $css), 0, 16).'.css';
        $html = view('blogs.published', ['blog' => $draft, 'website' => $website, 'url' => $url, 'cssPath' => $cssPath])->render();
        $content = [$path => $html, $cssPath => $css];
        $files = $manifest;
        foreach ($content as $file => $bytes) {
            $files[$file] = sha1($bytes);
        }
        $deploy = $this->json('POST', '/sites/'.$siteId.'/deploys', [
            'files' => $files, 'draft' => true, 'async' => false, 'title' => 'Approved article: '.$draft->slug,
        ]);
        if (! $this->validDeploy($deploy, $siteId) || ($deploy['context'] ?? null) !== 'deploy-preview'
            || ! array_key_exists('published_at', $deploy) || $deploy['published_at'] !== null) {
            throw new BlogPublicationException('Netlify did not create a safe draft deployment.');
        }
        $deployId = (string) $deploy['id'];
        $required = $deploy['required'] ?? [];
        if (! is_array($required) || ! empty($deploy['required_functions']) || ! empty($deploy['required_edge_functions']) || ! empty($deploy['required_server'])) {
            throw new BlogPublicationException('The website needs deployment resources this publisher cannot preserve.');
        }
        foreach ($required as $sha) {
            $file = array_search($sha, $files, true);
            if (! is_string($file) || ! isset($content[$file])) {
                throw new BlogPublicationException('Netlify could not reuse the complete website snapshot. No production deployment was changed.');
            }
            $encoded = implode('/', array_map(rawurlencode(...), explode('/', ltrim($file, '/'))));
            $this->request('PUT', '/deploys/'.$deployId.'/files/'.$encoded, $content[$file]);
        }
        $this->waitUntilReady($deployId, $siteId);
        if ($this->manifest($deployId) !== $this->sorted($files)) {
            throw new BlogPublicationException('The draft website snapshot did not match the expected files.');
        }
        $preview = 'https://'.$deployId.'--'.$site['name'].'.netlify.app/blog/'.$draft->slug.'/';
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withoutRedirecting()->get($preview);
        } catch (Throwable) {
            throw new BlogPublicationException('The approved article preview could not be checked.');
        }
        if (! $response->successful() || ! str_contains($response->body(), 'sm-manager-blog:'.$draft->id)
            || ! str_contains($response->body(), e($draft->title))) {
            throw new BlogPublicationException('The approved article preview could not be verified.');
        }

        $current = $this->current($siteId, $baseId);
        $wasLocked = $draft->publication_deploy_attempt_id === $draft->publication_attempt_id
            && $draft->publication_base_deploy_id === $baseId && $draft->publication_base_was_locked !== null
            ? $draft->publication_base_was_locked : ($current['locked'] ?? false) === true;
        $authorizePromotion($deployId, $url, $baseId, $wasLocked, function (callable $assertApproved) use ($siteId, $baseId, $deployId, $wasLocked): void {
            try {
                $current = $this->current($siteId, $baseId);
                if (($current['locked'] ?? false) !== true) {
                    try {
                        $locked = $this->json('POST', '/deploys/'.$baseId.'/lock');
                    } catch (BlogPublicationException) {
                        $locked = $this->current($siteId, $baseId);
                    }
                    if (($locked['locked'] ?? false) !== true) {
                        throw new BlogPublicationException('Netlify could not pause automatic publication while publishing this article.');
                    }
                }
                $this->assertCurrent($siteId, $baseId);
                $assertApproved();
                try {
                    $restored = $this->json('POST', '/sites/'.$siteId.'/deploys/'.$deployId.'/restore');
                    if (($restored['id'] ?? null) !== $deployId) {
                        throw new BlogPublicationException('Netlify did not confirm the approved production deployment.');
                    }
                } catch (BlogPublicationException $exception) {
                    if (($this->json('GET', '/sites/'.$siteId)['published_deploy']['id'] ?? null) !== $deployId) {
                        throw $exception;
                    }
                }
                $this->assertCurrent($siteId, $deployId);
            } finally {
                $this->restoreLockState($siteId, $baseId, $deployId, $wasLocked);
            }
        });
    }

    public function isPublished(string $siteId, string $deployId): bool
    {
        return ($this->json('GET', '/sites/'.$siteId)['published_deploy']['id'] ?? null) === $deployId;
    }

    public function restoreLockState(string $siteId, string $baseId, string $deployId, bool $wasLocked): void
    {
        $current = $this->json('GET', '/sites/'.$siteId)['published_deploy'] ?? [];
        if (is_array($current) && in_array($current['id'] ?? null, [$baseId, $deployId], true)
            && (($current['locked'] ?? false) === true) !== $wasLocked) {
            $this->json('POST', '/deploys/'.$current['id'].($wasLocked ? '/lock' : '/unlock'));
        }
    }

    private function assertStatic(string $siteId, string $deployId): void
    {
        $deploy = $this->json('GET', '/deploys/'.$deployId);
        $functions = $this->json('GET', '/sites/'.$siteId.'/functions');
        $config = $deploy['config'] ?? [];
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (! is_array($config) || ! $this->validDeploy($deploy, $siteId) || ($deploy['manual_deploy'] ?? false) !== true
            || ! empty($deploy['build_id']) || ! empty($deploy['framework']) || $functions !== []
            || ! empty($deploy['available_functions']) || ! empty($deploy['required_functions'])
            || ! empty($deploy['required_edge_functions']) || ! empty($deploy['required_server'])
            || ! empty($deploy['function_schedules']) || ! empty($deploy['edge_functions_present'])
            || ! empty($deploy['edge_functions']) || ! empty($deploy['server'])
            || ! empty($config['redirects']) || ! empty($config['headers']) || ! empty($config['functions'])
            || ! empty($config['edge_functions']) || ! empty($config['server'])) {
            throw new BlogPublicationException('This publisher preserves static websites. This deployment needs a supported integration for its functions or build configuration.');
        }
    }

    private function assertCurrent(string $siteId, string $expected): void
    {
        $this->current($siteId, $expected);
    }

    /** @return array<string, mixed> */
    private function current(string $siteId, string $expected): array
    {
        $current = $this->json('GET', '/sites/'.$siteId)['published_deploy'] ?? null;
        if (! is_array($current) || ($current['id'] ?? null) !== $expected) {
            throw new BlogPublicationException('The website changed during preparation. Retry to include the latest website changes.');
        }

        return $current;
    }

    /** @param array<string, mixed> $deploy */
    private function validDeploy(array $deploy, string $siteId): bool
    {
        return ($deploy['site_id'] ?? null) === $siteId && is_string($deploy['id'] ?? null)
            && preg_match('/^[a-f0-9]{24}$/', $deploy['id']) === 1;
    }

    private function waitUntilReady(string $id, string $siteId): void
    {
        $deadline = microtime(true) + 90;
        for ($poll = 0; $poll < 30; $poll++) {
            $deploy = $this->json('GET', '/deploys/'.$id);
            if (! $this->validDeploy($deploy, $siteId) || in_array($deploy['state'] ?? null, ['error', 'failed', 'canceled'], true)) {
                throw new BlogPublicationException('Netlify could not prepare the draft deployment.');
            }
            if (($deploy['state'] ?? null) === 'ready') {
                return;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(1_000_000);
        }
        throw new BlogPublicationException('The draft deployment is taking longer than expected. Retry after checking Netlify.');
    }

    /** @return array<string, string> */
    private function manifest(string $id): array
    {
        $response = $this->request('GET', '/deploys/'.$id.'/files');
        $entries = $response->json();
        if (! is_array($entries) || ! array_is_list($entries) || $entries === [] || count($entries) > 50_000 || $response->header('Link') !== '') {
            throw new BlogPublicationException('The complete website file manifest could not be read.');
        }
        $files = [];
        foreach ($entries as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            $sha = is_array($entry) ? ($entry['sha'] ?? null) : null;
            if (! is_string($path) || ! str_starts_with($path, '/') || preg_match('/[\x00-\x1f\\\\?#]/', $path)
                || array_intersect(explode('/', ltrim($path, '/')), ['', '.', '..']) !== []
                || ! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/', $sha) || isset($files[$path])) {
                throw new BlogPublicationException('The website file manifest includes an unsupported path or content hash.');
            }
            $files[$path] = $sha;
        }

        return $this->sorted($files);
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function sorted(array $files): array
    {
        ksort($files);

        return $files;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<array-key, mixed>
     */
    private function json(string $method, string $path, ?array $data = null): array
    {
        $value = $this->request($method, $path, $data)->json();
        if (! is_array($value)) {
            throw new BlogPublicationException('Netlify returned an incomplete deployment response.');
        }

        return $value;
    }

    /** @param array<string, mixed>|string|null $data */
    private function request(string $method, string $path, array|string|null $data = null): Response
    {
        $token = (string) config('services.netlify.token');
        if ($token === '') {
            throw new BlogPublicationException('Website publishing is not connected.');
        }
        try {
            $request = $this->client($token);
            $response = is_string($data)
                ? $request->withBody($data, 'application/octet-stream')->send($method, self::API.$path)
                : $request->send($method, self::API.$path, $data === null ? [] : ['json' => $data]);
        } catch (Throwable) {
            throw new BlogPublicationException('Netlify could not be reached. The approved article can be retried.');
        }
        if (! $response->successful()) {
            throw new BlogPublicationException('Netlify could not complete this request. Check the server connection and deployment status.');
        }

        return $response;
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting();
    }
}
