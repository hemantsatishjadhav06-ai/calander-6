<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\BlogDraft;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Blogs\BlogDraftService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, string|null> */
function blogContent(array $overrides = []): array
{
    return [
        'title' => 'A practical guide to comparing homes',
        'slug' => 'comparing-homes',
        'body' => 'Review the location and property documentation before making a decision.',
        'excerpt' => 'Questions to ask while comparing homes.',
        'featured_image_url' => null,
        'featured_image_alt' => null,
        'seo_title' => 'Comparing homes',
        'seo_description' => 'A practical home comparison checklist.',
        'canonical_url' => null,
        ...$overrides,
    ];
}

function blogRevision(BlogDraft $blog): string
{
    return app(BlogDraftService::class)->revision($blog->fresh());
}

function blogAwaitingApproval(BlogDraft $blog): string
{
    $revision = blogRevision($blog);
    test()->post(route('blogs.request-review', $blog), ['revision' => $revision])->assertRedirect(route('blogs.preview', $blog));

    return $revision;
}

function approvedBlog(BlogDraft $blog): string
{
    $revision = blogAwaitingApproval($blog);
    test()->post(route('blogs.approve', $blog), ['revision' => $revision])->assertRedirect(route('blogs.preview', $blog));

    return $revision;
}

test('blog drafts require authentication and have no public preview route', function (): void {
    $blog = BlogDraft::factory()->create();
    $this->get(route('blogs.index'))->assertRedirect(route('login'));
    $this->get(route('blogs.create'))->assertRedirect(route('login'));
    $this->get(route('blogs.edit', $blog))->assertRedirect(route('login'));
    $this->get(route('blogs.preview', $blog))->assertRedirect(route('login'));
    $this->post(route('blogs.store'), blogContent())->assertRedirect(route('login'));
    $this->get('/blogs/'.$blog->id)->assertNotFound();
});

test('saving a blog persists its content privately and excludes client-supplied approvals and ownership', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $other = Workspace::factory()->create();
    $this->post(route('blogs.store'), [
        ...blogContent(), 'workspace_id' => $other->id, 'author_id' => $other->owner_id,
        'approved_by' => $owner->id, 'approved_at' => now()->toIso8601String(), 'approved_revision' => str_repeat('a', 64),
    ])->assertRedirect();
    $blog = BlogDraft::withoutGlobalScopes()->sole();

    expect($blog->workspace_id)->toBe($workspace->id)
        ->and($blog->author_id)->toBe($owner->id)
        ->and($blog->body)->toBe(blogContent()['body'])
        ->and($blog->approved_revision)->toBeNull();
    $response = $this->get(route('blogs.preview', $blog))
        ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page): Assert => $page->component('blogs/preview')
            ->where('blog.status', 'draft')->where('publication.available', false)->where('blog.body', $blog->body));
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    Http::assertNothingSent();
});

test('every blog read and review action rejects another workspace id without exposing the draft', function (): void {
    [, $workspace] = ownerActingIn();
    $local = BlogDraft::factory()->for($workspace)->create(['title' => 'Our private article']);
    $foreign = BlogDraft::factory()->create(['title' => 'Other workspace confidential article']);
    $revision = blogRevision($foreign);

    $this->get(route('blogs.index'))->assertInertia(fn (Assert $page): Assert => $page->has('blogs.data', 1)->where('blogs.data.0.id', $local->id));
    foreach (['blogs.edit', 'blogs.preview'] as $route) {
        $this->get(route($route, $foreign))->assertNotFound();
    }
    foreach (['blogs.request-review', 'blogs.approve', 'blogs.reject'] as $route) {
        $this->postJson(route($route, $foreign), ['revision' => $revision])->assertNotFound();
    }
    $this->patchJson(route('blogs.update', $foreign), [...blogContent(), 'revision' => $revision])->assertNotFound();
    expect($foreign->fresh()->title)->toBe('Other workspace confidential article');
});

test('members can edit their workspace blog but cannot request or grant review approval', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create(['author_id' => $owner->id]);
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->actingAs($member)->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.can_review', false));
    $this->patch(route('blogs.update', $blog), [...blogContent(), 'revision' => blogRevision($blog)])->assertRedirect();
    foreach (['blogs.request-review', 'blogs.approve', 'blogs.reject'] as $route) {
        $this->postJson(route($route, $blog), ['revision' => blogRevision($blog)])->assertForbidden();
    }
    expect($blog->fresh()->approved_revision)->toBeNull();
});

test('the owner can request review approve the exact version and return it for changes without publishing', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create();
    $revision = blogAwaitingApproval($blog);
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'awaiting_approval'));
    $this->post(route('blogs.approve', $blog), ['revision' => $revision])->assertRedirect();
    expect($blog->fresh()->approved_revision)->toBe($revision)
        ->and($blog->fresh()->approved_by)->toBe($owner->id)
        ->and($blog->fresh()->approved_at)->not->toBeNull();
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'approved')->where('publication.available', false));
    $this->post('/blogs/'.$blog->id.'/publish', ['revision' => $revision])->assertNotFound();
    $this->post(route('blogs.reject', $blog), ['revision' => $revision, 'reason' => 'Please verify the location description.'])->assertRedirect();
    expect($blog->fresh()->approved_revision)->toBeNull()
        ->and($blog->fresh()->rejected_by)->toBe($owner->id);
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page
        ->where('blog.status', 'rejected')->where('blog.rejection_reason', 'Please verify the location description.'));
    Http::assertNothingSent();
});

test('approving without requesting review or with a stale revision never grants approval', function (): void {
    [, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create(blogContent());
    $revision = blogRevision($blog);
    $this->postJson(route('blogs.approve', $blog), ['revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('revision');
    blogAwaitingApproval($blog);
    $this->patch(route('blogs.update', $blog), [...blogContent(['body' => 'A revised verified article.']), 'revision' => $revision])->assertRedirect();
    $this->postJson(route('blogs.approve', $blog), ['revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->patchJson(route('blogs.update', $blog), [...blogContent(['body' => 'An outdated edit.']), 'revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('revision');
    expect($blog->fresh()->body)->toBe('A revised verified article.')
        ->and($blog->fresh()->approved_revision)->toBeNull();
});

test('changes to every reviewed content field invalidate approval', function (string $field, string $value): void {
    [, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create(blogContent());
    $revision = approvedBlog($blog);
    $this->patch(route('blogs.update', $blog), [...blogContent([$field => $value]), 'revision' => $revision])->assertRedirect();
    $blog->refresh();
    expect($blog->content_revision)->toBe(2)->and($blog->approved_revision)->toBeNull()
        ->and($blog->review_requested_revision)->toBeNull();
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'draft'));
})->with([
    ['title', 'A new title'], ['slug', 'new-article'], ['body', 'A new article body.'], ['excerpt', 'A new excerpt.'],
    ['featured_image_url', 'https://neopolisinfra.com/images/project.jpg'], ['featured_image_alt', 'A verified site photograph'],
    ['seo_title', 'A new search title'], ['seo_description', 'A new search description'], ['canonical_url', 'https://neopolisinfra.com/blog/new-article'],
]);

test('saving identical content retains approval and does not advance the content revision', function (): void {
    [, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create(blogContent());
    $revision = approvedBlog($blog);
    $this->patch(route('blogs.update', $blog), [...blogContent(), 'revision' => $revision])->assertRedirect();
    expect($blog->fresh()->content_revision)->toBe(1)->and($blog->fresh()->approved_revision)->toBe($revision);
});

test('approval binds the actual content and website destination even if changed outside the editor', function (): void {
    [, $workspace] = ownerActingIn();
    $profile = BrandProfile::factory()->for($workspace)->create(['website_url' => 'https://neopolisinfra.com']);
    $blog = BlogDraft::factory()->for($workspace)->create(blogContent());
    $revision = approvedBlog($blog);
    $blog->forceFill(['body' => 'Changed outside the editor.'])->save();
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'draft'));
    $this->postJson(route('blogs.approve', $blog), ['revision' => $revision])->assertUnprocessable();
    approvedBlog($blog);
    $profile->update(['website_url' => 'https://morespace.netlify.app']);
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'draft'));
});

test('ownership transfer invalidates approval and the former owner cannot approve', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create();
    $revision = approvedBlog($blog);
    $newOwner = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $newOwner->id, 'role' => WorkspaceRole::Owner]);
    $workspace->forceFill(['owner_id' => $newOwner->id])->save();
    $this->get(route('blogs.preview', $blog))->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'draft')->where('blog.can_review', false));
    $this->postJson(route('blogs.approve', $blog), ['revision' => $revision])->assertForbidden();
    expect($blog->fresh()->approved_by)->toBeNull();
});

test('revoked workspace membership cannot read or mutate a private blog draft', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create();
    $revision = blogRevision($blog);
    WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $owner->id)->delete();
    $this->get(route('blogs.preview', $blog))->assertNotFound();
    $this->get(route('blogs.index'))->assertNotFound();
    $this->patchJson(route('blogs.update', $blog), [...blogContent(), 'revision' => $revision])->assertNotFound();
    $this->postJson(route('blogs.approve', $blog), ['revision' => $revision])->assertNotFound();
});

test('blog URLs reject unsafe schemes private hosts credentials and SVG images', function (string $url): void {
    ownerActingIn();
    $this->postJson(route('blogs.store'), blogContent(['featured_image_url' => $url]))
        ->assertUnprocessable()->assertJsonValidationErrors('featured_image_url');
    expect(BlogDraft::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'javascript:alert(1)', 'http://neopolisinfra.com/image.jpg', 'https://user:secret@neopolisinfra.com/image.jpg',
    'https://127.0.0.1/image.jpg', 'https://[::1]/image.jpg', 'https://localhost/image.jpg',
    'https://storage.internal/image.jpg', 'https://neopolisinfra.com:8080/image.jpg', 'https://neopolisinfra.com/image.svg',
]);

test('trusted blog revision uses the explicitly bound workspace destination regardless of ambient workspace context', function (): void {
    [, $workspace] = ownerActingIn();
    $other = Workspace::factory()->create();
    $profile = BrandProfile::factory()->for($other)->create(['website_url' => 'https://morespace.netlify.app']);
    $blog = BlogDraft::factory()->for($other)->create();
    Context::add('workspace_id', $workspace->id);
    $service = app(BlogDraftService::class);
    $destination = $service->destination($other->id);
    expect($destination['website_url'])->toBe($profile->website_url)
        ->and($service->revision($blog))->toBe($service->revision($blog, $destination));
});

test('members never see an approved blog when the workspace owner has no live owner membership', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $blog = BlogDraft::factory()->for($workspace)->create();
    approvedBlog($blog);
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $owner->id)->delete();
    $this->actingAs($member)->get(route('blogs.preview', $blog))
        ->assertInertia(fn (Assert $page): Assert => $page->where('blog.status', 'awaiting_approval')->where('blog.approved_by', null));
});

test('a prevalidated blog create or edit cannot claim a slug another request has since saved', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $service = app(BlogDraftService::class);
    $prevalidated = blogContent();
    $first = $service->create($owner, $prevalidated);
    expect(fn () => $service->create($owner, $prevalidated))->toThrow(ValidationException::class);
    $second = $service->create($owner, blogContent(['slug' => 'different-article']));
    $revision = blogRevision($second);
    expect(fn () => $service->update($owner, $second, $prevalidated, $revision))->toThrow(ValidationException::class);
    expect($second->fresh()->slug)->toBe('different-article')
        ->and(BlogDraft::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('slug', $first->slug)->count())->toBe(1);
});

test('blog slug uniqueness is tenant scoped and enforced even by direct writes', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $first = app(BlogDraftService::class)->create($owner, blogContent());
    $other = Workspace::factory()->create();
    $second = BlogDraft::factory()->for($other)->create(['slug' => $first->slug]);
    expect($second->slug)->toBe($first->slug);
    $this->postJson(route('blogs.store'), blogContent())->assertUnprocessable()->assertJsonValidationErrors('slug');
    expect(fn () => BlogDraft::factory()->for($workspace)->create(['slug' => $first->slug]))
        ->toThrow(UniqueConstraintViolationException::class);
});
