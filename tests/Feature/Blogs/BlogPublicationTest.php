<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Jobs\PublishBlogDraft;
use App\Models\BlogDraft;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Blogs\BlogDraftService;
use App\Services\Blogs\BlogPublicationService;
use App\Services\Blogs\NetlifyBlogPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @return array{User, Workspace, BlogDraft, string} */
function connectedWebsiteBlog(bool $approved = true): array
{
    [$owner, $workspace] = ownerActingIn();
    config([
        'blogs.publishing_enabled' => true,
        'blogs.owner_email' => $owner->email,
        'services.netlify.token' => 'server-only-netlify-test-credential',
    ]);
    BrandProfile::factory()->for($workspace)->create([
        'website_url' => 'https://neopolisinfra.com',
        'netlify_site_id' => '47e0a5cc-d9d9-428b-a36b-beea806bff6f',
        'repository_url' => null,
    ]);
    $blog = BlogDraft::factory()->for($workspace)->create([
        'author_id' => $owner->id, 'title' => 'A home checklist <script>alert(1)</script>',
        'slug' => 'home-checklist', 'body' => "First review the documentation.\n\n<script>Untrusted text stays text.</script>",
    ]);
    $revision = app(BlogDraftService::class)->revision($blog);
    if ($approved) {
        app(BlogDraftService::class)->review($owner, $blog, 'request', $revision);
        app(BlogDraftService::class)->review($owner, $blog, 'approve', $revision);
    }

    return [$owner, $workspace, $blog->refresh(), $revision];
}

/** @return array<string, mixed> */
function netlifyStaticDeploy(string $id, bool $draft = false, bool $locked = false): array
{
    return [
        'id' => $id, 'site_id' => '47e0a5cc-d9d9-428b-a36b-beea806bff6f', 'state' => 'ready',
        'manual_deploy' => true, 'context' => $draft ? 'deploy-preview' : 'production',
        'published_at' => $draft ? null : now()->toIso8601String(), 'locked' => $locked, 'build_id' => null, 'framework' => null,
        'required' => [], 'available_functions' => [], 'required_functions' => [],
        'required_edge_functions' => [], 'required_server' => [], 'function_schedules' => [],
    ];
}

/** @return object{files: array<string, string>, uploads: array<string, string>, published: string, locked: bool, restore: int, lock: int, unlock: int} */
function fakeStaticNetlify(?Closure $intercept = null): object
{
    $state = (object) [
        'files' => [], 'uploads' => [], 'published' => str_repeat('a', 24),
        'locked' => false, 'restore' => 0, 'lock' => 0, 'unlock' => 0,
    ];
    $existing = [
        '/index.html' => str_repeat('1', 40), '/admin/index.html' => str_repeat('2', 40),
        '/assets/project.jpg' => str_repeat('3', 40), '/_redirects' => str_repeat('4', 40),
        '/netlify.toml' => str_repeat('5', 40), '/blog/existing.html' => str_repeat('6', 40),
    ];
    Http::fake(function (Request $request) use ($state, $existing, $intercept) {
        if ($intercept !== null && ($response = $intercept($request, $state)) !== null) {
            return $response;
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        $api = '/api/v1';
        $site = $api.'/sites/47e0a5cc-d9d9-428b-a36b-beea806bff6f';
        if ($path === $site && $request->method() === 'GET') {
            return Http::response([
                'id' => '47e0a5cc-d9d9-428b-a36b-beea806bff6f', 'name' => 'neopolis-infra', 'ssl_url' => 'https://neopolisinfra.com',
                'published_deploy' => netlifyStaticDeploy($state->published, locked: $state->locked),
            ]);
        }
        if ($path === $site.'/functions') {
            return Http::response('{}', 200, ['Content-Type' => 'application/json']);
        }
        if ($path === $site.'/deploys' && $request->method() === 'POST') {
            expect($request['draft'])->toBeTrue();
            $state->files = $request['files'];

            return Http::response([
                ...netlifyStaticDeploy(str_repeat('b', 24), true),
                'required' => array_values(array_diff($state->files, $existing)),
            ], 201);
        }
        if (preg_match('~/deploys/([ab]{24})/files$~', (string) $path, $match)) {
            $files = $match[1] === str_repeat('a', 24) ? $existing : $state->files;

            return Http::response(array_map(fn ($path, $sha): array => ['path' => $path, 'sha' => $sha], array_keys($files), $files));
        }
        if (preg_match('~/deploys/b{24}/files/(.+)$~', (string) $path, $match)) {
            $state->uploads['/'.rawurldecode($match[1])] = $request->body();

            return Http::response([]);
        }
        if (preg_match('~/deploys/([ab]{24})$~', (string) $path, $match)) {
            return Http::response(netlifyStaticDeploy($match[1], $match[1] === str_repeat('b', 24)));
        }
        if (str_ends_with((string) $path, '/lock')) {
            $state->locked = true;
            $state->lock++;

            return Http::response(netlifyStaticDeploy($state->published, locked: true));
        }
        if (str_ends_with((string) $path, '/unlock')) {
            $state->locked = false;
            $state->unlock++;

            return Http::response(netlifyStaticDeploy($state->published));
        }
        if (str_ends_with((string) $path, '/restore')) {
            $state->restore++;
            $state->published = str_repeat('b', 24);

            return Http::response(netlifyStaticDeploy($state->published));
        }
        if ($request->url() === 'https://'.str_repeat('b', 24).'--neopolis-infra.netlify.app/blog/home-checklist/') {
            expect($request->hasHeader('Authorization'))->toBeFalse();

            return Http::response($state->uploads['/blog/home-checklist/index.html'], 200, ['Content-Type' => 'text/html']);
        }
        throw new RuntimeException('Unexpected test request: '.$request->method().' '.$request->url());
    });

    return $state;
}

function runQueuedWebsiteBlog(BlogDraft $blog, string $revision): void
{
    $current = $blog->refresh();
    new PublishBlogDraft($current->id, $current->workspace_id, $revision, $current->publication_attempt_id,
        '47e0a5cc-d9d9-428b-a36b-beea806bff6f')->handle(
            app(BlogPublicationService::class), app(BlogDraftService::class), app(NetlifyBlogPublisher::class),
        );
}

test('approved blogs remain private until the connected owner explicitly requests publishing', function (): void {
    [, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page
        ->where('publication.available', true)->where('blog.publication_status', 'idle')->where('blog.published_url', null)
        ->missing('publication.token')->missing('blog.publication_attempt_id'));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $this->post(route('blogs.publish', $blog), ['revision' => $revision])->assertRedirect(route('blogs.preview', $blog));
    Queue::assertPushed(PublishBlogDraft::class, 1);
    $this->post(route('blogs.publish', $blog), ['revision' => $revision])->assertRedirect();
    Queue::assertPushed(PublishBlogDraft::class, 1);
    expect($blog->fresh()->publication_status)->toBe('queued');
    Http::assertNothingSent();
});

test('publishing rejects unapproved or stale content and unconnected credentials without enqueuing', function (string $case): void {
    [, , $blog, $revision] = connectedWebsiteBlog($case !== 'unapproved');
    Queue::fake();
    match ($case) {
        'stale' => $blog->forceFill(['body' => 'Changed after review'])->save(),
        'disabled' => config(['blogs.publishing_enabled' => false]),
        'credential' => config(['services.netlify.token' => null]),
        default => null,
    };
    $this->postJson(route('blogs.publish', $blog), ['revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('publication');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['unapproved', 'stale', 'disabled', 'credential']);

test('the server website credential cannot be claimed by a different workspace owner', function (): void {
    [, , $blog, $revision] = connectedWebsiteBlog();
    config(['blogs.owner_email' => 'different-owner@example.com']);
    Queue::fake();
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('publication.available', false));
    $this->postJson(route('blogs.publish', $blog), ['revision' => $revision])->assertUnprocessable();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('members and other workspaces cannot publish an owners approved blog', function (): void {
    [, $workspace, $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->actingAs($member)->postJson(route('blogs.publish', $blog), ['revision' => $revision])->assertForbidden();
    ownerActingIn();
    $this->postJson(route('blogs.publish', $blog), ['revision' => $revision])->assertNotFound();
    Queue::assertNothingPushed();
});

test('a queued approved article preserves the complete live website and publishes only verified escaped content', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify();
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('published')
        ->and($blog->fresh()->published_revision)->toBe($revision)
        ->and($blog->fresh()->published_url)->toBe('https://neopolisinfra.com/blog/home-checklist/')
        ->and($state->files['/admin/index.html'])->toBe(str_repeat('2', 40))
        ->and($state->files['/assets/project.jpg'])->toBe(str_repeat('3', 40))
        ->and($state->files['/_redirects'])->toBe(str_repeat('4', 40))
        ->and($state->files['/netlify.toml'])->toBe(str_repeat('5', 40))
        ->and($state->files['/blog/existing.html'])->toBe(str_repeat('6', 40))
        ->and($state->uploads['/blog/home-checklist/index.html'])->toContain('&lt;script&gt;')->not->toContain('<script>')
        ->and($state->restore)->toBe(1)->and($state->lock)->toBe(1)->and($state->unlock)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/restore'));
    $count = count(Http::recorded());
    runQueuedWebsiteBlog($blog, $revision);
    expect(count(Http::recorded()))->toBe($count);
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    Queue::assertPushed(PublishBlogDraft::class, 1);
});

test('a queued blog loses permission before any provider call when its approval or destination changes', function (string $case): void {
    [$owner, $workspace, $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    match ($case) {
        'content' => $blog->forceFill(['body' => 'Edited while waiting'])->save(),
        'target' => BrandProfile::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first()->update(['website_url' => 'https://morespace.netlify.app']),
        'owner' => WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $owner->id)->delete(),
    };
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('failed')->and($blog->fresh()->published_at)->toBeNull();
    Http::assertNothingSent();
})->with(['content', 'target', 'owner']);

test('website changes during preparation never overwrite the newer live deployment', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify(function (Request $request, object $state) {
        if (str_contains($request->url(), '--neopolis-infra.netlify.app')) {
            $state->published = str_repeat('c', 24);
        }

        return null;
    });
    runQueuedWebsiteBlog($blog, $revision);
    expect($state->restore)->toBe(0)->and($state->lock)->toBe(0)
        ->and($blog->fresh()->publication_status)->toBe('failed')
        ->and($blog->fresh()->publication_error)->toContain('website changed');
});

test('the approval is checked again after the draft preview immediately before production promotion', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify(function (Request $request) use ($blog) {
        if (str_ends_with($request->url(), '/lock')) {
            DB::table('blog_drafts')->where('id', $blog->id)->update(['body' => 'Externally changed after preview']);
        }

        return null;
    });
    runQueuedWebsiteBlog($blog, $revision);
    expect($state->restore)->toBe(0)->and($state->unlock)->toBe(1)
        ->and($blog->fresh()->publication_status)->toBe('failed')->and($blog->fresh()->published_at)->toBeNull();
});

test('unsafe provider responses and unsupported website resources fail without changing production', function (string $case): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify(function (Request $request) use ($case) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($case === 'functions' && str_ends_with($path, '/functions')) {
            return Http::response([['name' => 'production-leads']]);
        }
        if ($case === 'edge' && $path === '/api/v1/deploys/'.str_repeat('a', 24)) {
            return Http::response([...netlifyStaticDeploy(str_repeat('a', 24)), 'edge_functions_present' => true]);
        }
        if ($case === 'compiled-config' && $path === '/api/v1/deploys/'.str_repeat('a', 24)) {
            return Http::response([...netlifyStaticDeploy(str_repeat('a', 24)), 'config' => ['headers' => [['for' => '/admin/*']]]]);
        }
        if ($case === 'missing-old-content' && str_ends_with($path, '/deploys') && $request->method() === 'POST') {
            return Http::response([...netlifyStaticDeploy(str_repeat('b', 24), true), 'required' => [str_repeat('2', 40)]]);
        }
        if ($case === 'redirect' && str_ends_with($path, '/files') && str_contains($path, str_repeat('a', 24))) {
            return Http::response([], 302, ['Location' => 'https://example.com/leak']);
        }
        if ($case === 'raw-error' && str_ends_with($path, '/functions')) {
            return Http::response(['error' => 'server-only-netlify-test-credential private details'], 403);
        }
        if ($case === 'traversal' && str_ends_with($path, '/files') && str_contains($path, str_repeat('a', 24))) {
            return Http::response([['path' => '/../private.txt', 'sha' => str_repeat('1', 40)]]);
        }

        return null;
    });
    runQueuedWebsiteBlog($blog, $revision);
    expect($state->restore)->toBe(0)->and($blog->fresh()->publication_status)->toBe('failed')
        ->and($blog->fresh()->publication_error)->not->toContain('server-only-netlify-test-credential')
        ->and($blog->fresh()->published_at)->toBeNull();
})->with(['functions', 'edge', 'compiled-config', 'missing-old-content', 'redirect', 'raw-error', 'traversal']);

test('preexisting Netlify auto publication locks are preserved on the new deployment', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify(function (Request $request, object $state) {
        if (str_ends_with($request->url(), '/restore')) {
            $state->published = str_repeat('b', 24);
            $state->locked = false;
            $state->restore++;

            return Http::response(netlifyStaticDeploy($state->published));
        }

        return null;
    });
    $state->locked = true;
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('published')->and($state->locked)->toBeTrue()
        ->and($state->restore)->toBe(1)->and($state->unlock)->toBe(0)->and($state->lock)->toBe(1);
});

test('uncertain provider lock and promotion responses are reconciled before repeating operations', function (string $operation): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify(function (Request $request, object $state) use ($operation) {
        if ($operation === 'lock' && str_ends_with($request->url(), '/lock')) {
            $state->locked = true;
            $state->lock++;

            return Http::failedConnection();
        }
        if ($operation === 'restore' && str_ends_with($request->url(), '/restore')) {
            $state->published = str_repeat('b', 24);
            $state->restore++;

            return Http::failedConnection();
        }

        return null;
    });
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('published')->and($state->restore)->toBe(1)
        ->and($state->lock)->toBe(1)->and($state->unlock)->toBe(1)->and($state->locked)->toBeFalse();
})->with(['lock', 'restore']);

test('remote publication success is retained when the first local publication save fails', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $state = fakeStaticNetlify();
    $failOnce = true;
    BlogDraft::saving(function (BlogDraft $current) use ($blog, &$failOnce): void {
        if ($current->id === $blog->id && $current->publication_status === 'published' && $failOnce) {
            $failOnce = false;
            throw new RuntimeException('Simulated database write failure after remote promotion');
        }
    });
    runQueuedWebsiteBlog($blog, $revision);
    expect($state->restore)->toBe(1)->and($blog->fresh()->publication_status)->toBe('published')
        ->and($blog->fresh()->published_revision)->toBe($revision)
        ->and($blog->fresh()->publication_deploy_attempt_id)->toBe($blog->fresh()->publication_attempt_id);
});

test('a worker retry resumes an interrupted publication attempt and preserves the original lock state', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $blog->refresh()->forceFill([
        'publication_status' => 'publishing', 'publication_deploy_id' => str_repeat('b', 24),
        'publication_deploy_attempt_id' => $blog->publication_attempt_id,
        'publication_url' => 'https://neopolisinfra.com/blog/home-checklist/',
        'publication_base_deploy_id' => str_repeat('a', 24), 'publication_base_was_locked' => false,
    ])->save();
    $state = fakeStaticNetlify();
    $state->locked = true;
    runQueuedWebsiteBlog($blog, $revision);
    expect($state->restore)->toBe(1)->and($state->unlock)->toBe(1)->and($state->locked)->toBeFalse()
        ->and($blog->fresh()->publication_status)->toBe('published');
});

test('an interrupted worker records an already published candidate without deploying another website snapshot', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $blog->refresh()->forceFill([
        'publication_status' => 'publishing', 'publication_deploy_id' => str_repeat('b', 24),
        'publication_deploy_attempt_id' => $blog->publication_attempt_id,
        'publication_url' => 'https://neopolisinfra.com/blog/home-checklist/',
        'publication_base_deploy_id' => str_repeat('a', 24), 'publication_base_was_locked' => false,
    ])->save();
    $state = fakeStaticNetlify();
    $state->published = str_repeat('b', 24);
    $state->locked = true;
    runQueuedWebsiteBlog($blog, $revision);
    expect($state->restore)->toBe(0)->and($state->files)->toBe([])->and($state->unlock)->toBe(1)
        ->and($blog->fresh()->publication_status)->toBe('published')->and($blog->fresh()->published_revision)->toBe($revision);
});

test('a timed out worker restores its temporary base lock before a fresh retry', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $blog->refresh()->forceFill([
        'publication_status' => 'publishing', 'publication_deploy_id' => str_repeat('b', 24),
        'publication_deploy_attempt_id' => $blog->publication_attempt_id,
        'publication_url' => 'https://neopolisinfra.com/blog/home-checklist/',
        'publication_base_deploy_id' => str_repeat('a', 24), 'publication_base_was_locked' => false,
    ])->save();
    $state = fakeStaticNetlify();
    $state->locked = true;
    new PublishBlogDraft($blog->id, $blog->workspace_id, $revision, $blog->publication_attempt_id,
        '47e0a5cc-d9d9-428b-a36b-beea806bff6f')->failed(new RuntimeException('Worker timed out'));
    expect($blog->fresh()->publication_status)->toBe('failed')->and($state->unlock)->toBe(1)->and($state->locked)->toBeFalse();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('published')->and($state->locked)->toBeFalse()->and($state->restore)->toBe(1);
});

test('failed publication retries cleanup of an uncertain unlock before a new attempt', function (): void {
    [$owner, , $blog, $revision] = connectedWebsiteBlog();
    Queue::fake();
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    $failPromotion = true;
    $state = fakeStaticNetlify(function (Request $request, object $state) use (&$failPromotion) {
        if ($failPromotion && str_ends_with($request->url(), '/restore')) {
            return Http::response([], 500);
        }
        if (str_ends_with($request->url(), '/unlock') && $state->unlock === 0) {
            $state->unlock++;

            return Http::failedConnection();
        }

        return null;
    });
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('failed')->and($state->locked)->toBeFalse()
        ->and($state->unlock)->toBe(2)->and($state->restore)->toBe(0);
    $failPromotion = false;
    app(BlogPublicationService::class)->request($owner, $blog, $revision);
    runQueuedWebsiteBlog($blog, $revision);
    expect($blog->fresh()->publication_status)->toBe('published')->and($state->locked)->toBeFalse();
});

test('content and review operations are blocked while a website publication is active', function (): void {
    [$owner, $workspace, $blog, $revision] = connectedWebsiteBlog();
    $blog->forceFill(['publication_status' => 'publishing'])->save();
    $this->postJson(route('blogs.reject', $blog), ['revision' => $revision])->assertConflict();
    expect(fn () => app(BlogDraftService::class)->update($owner, $blog, ['title' => 'A new title', 'slug' => $blog->slug], $revision))
        ->toThrow(HttpException::class);
    expect(fn () => $workspace->forceFill(['owner_id' => User::factory()->create()->id])->save())
        ->toThrow(HttpException::class);
    Http::assertNothingSent();
});
