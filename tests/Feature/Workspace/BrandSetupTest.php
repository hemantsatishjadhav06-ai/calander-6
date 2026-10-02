<?php

use App\Enums\Platform;
use App\Models\BlogDraft;
use App\Models\BrandProfile;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Blogs\BlogDraftService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['kit.workspaces.enabled' => true, 'subscriptions.enabled' => false]);
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);
    $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
});

test('brand setup uses confirmed pages and enables approval without fabricating connections', function () {
    $this->actingAs($this->owner)->post(route('brands.bootstrap'))->assertRedirect(route('brands.index'));
    $brands = BrandProfile::withoutGlobalScope('workspace')->with('workspace')->get()->keyBy(fn (BrandProfile $profile): string => $profile->workspace->name);

    expect($brands)->toHaveCount(2)
        ->and($brands['Neopolis']->facebook_page_id)->toBe('61595008380228')
        ->and($brands['More Space']->facebook_page_id)->toBe('585141221346435')
        ->and($brands['Neopolis']->instagram_username)->toBe('neopolis_infra')
        ->and($brands['More Space']->instagram_username)->toBe('morespace.ai')
        ->and($brands['More Space']->x_username)->toBeNull()
        ->and($brands['Neopolis']->workspace->requires_post_approval)->toBeTrue()
        ->and($brands['More Space']->workspace->requires_post_approval)->toBeTrue();
    expect(ConnectedAccount::withoutGlobalScopes()->count())->toBe(0);
    expect(Post::withoutGlobalScopes()->count())->toBe(14)
        ->and(BlogDraft::withoutGlobalScopes()->count())->toBe(2)
        ->and(Post::withoutGlobalScopes()->whereNotNull('scheduled_at')->count())->toBe(0)
        ->and(Post::withoutGlobalScopes()->whereNotNull('approved_revision')->count())->toBe(0);
    $this->get(route('brands.index'))->assertInertia(fn (Assert $page) => $page->has('draftPlan', 7));
    $this->get(route('blogs.index'))->assertInertia(fn (Assert $page) => $page->where('blogs.data.0.status', 'awaiting_approval'));
    expect(PostingSchedule::withoutGlobalScopes()->whereIn('workspace_id', $brands->pluck('workspace_id'))->pluck('timezone')->all())
        ->toBe(['Asia/Kolkata', 'Asia/Kolkata']);
});

test('repeated brand setup preserves edited metadata and does not duplicate workspaces', function () {
    $this->actingAs($this->owner)->post(route('brands.bootstrap'))->assertRedirect();
    $profile = BrandProfile::withoutGlobalScope('workspace')->where('instagram_username', 'morespace.ai')->firstOrFail();
    $profile->update(['x_username' => 'morespace_test']);
    $this->post(route('brands.bootstrap'))->assertRedirect();

    expect(BrandProfile::withoutGlobalScope('workspace')->count())->toBe(2)
        ->and(Workspace::where('owner_id', $this->owner->id)->count())->toBe(3)
        ->and($profile->fresh()->x_username)->toBe('morespace_test');
    expect(Post::withoutGlobalScopes()->count())->toBe(14)
        ->and(BlogDraft::withoutGlobalScopes()->count())->toBe(2);
});

test('brand details cannot be changed by a workspace member', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => 'member']);

    $this->actingAs($member)->patch(route('brands.update'), ['website_url' => 'https://example.com'])->assertForbidden();
    $this->post(route('brands.bootstrap'))->assertForbidden();
    expect(BrandProfile::withoutGlobalScopes()->count())->toBe(0);
});

test('brand reads and updates stay bound to the current workspace', function () {
    $own = BrandProfile::factory()->create(['workspace_id' => $this->workspace->id, 'website_url' => 'https://owner.example']);
    $other = BrandProfile::factory()->create(['website_url' => 'https://foreign.example']);
    $this->actingAs($this->owner)->get(route('brands.index'))->assertInertia(fn (Assert $page) => $page
        ->component('brands/index')->where('profile.website_url', 'https://owner.example')->has('connections', 3));
    $this->patch(route('brands.update'), ['website_url' => 'https://updated.example', 'workspace_id' => $other->workspace_id])->assertRedirect();

    expect($own->fresh()->website_url)->toBe('https://updated.example')
        ->and($other->fresh()->website_url)->toBe('https://foreign.example')
        ->and($this->workspace->fresh()->requires_post_approval)->toBeTrue();
});

test('revoked workspace ownership does not permit brand updates', function () {
    WorkspaceMembership::where('workspace_id', $this->workspace->id)->where('user_id', $this->owner->id)->delete();
    $this->actingAs($this->owner)->patch(route('brands.update'), ['website_url' => 'https://example.com'])->assertForbidden();
    $this->post(route('brands.bootstrap'))->assertForbidden();
});

test('brand setup respects disabled workspace creation', function () {
    config(['kit.workspaces.enabled' => false]);
    $this->actingAs($this->owner)->post(route('brands.bootstrap'))->assertForbidden();
    expect(BrandProfile::withoutGlobalScopes()->count())->toBe(0)
        ->and(Workspace::where('owner_id', $this->owner->id)->count())->toBe(1);
});

test('brand connection identity compares Facebook Page IDs rather than handles', function () {
    BrandProfile::factory()->create(['workspace_id' => $this->workspace->id, 'facebook_page_id' => '61595008380228']);
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Facebook, 'handle' => '61595008380228', 'remote_account_id' => '585141221346435']);
    $this->actingAs($this->owner)->get(route('brands.index'))->assertInertia(fn (Assert $page) => $page
        ->where('connections.1.status', 'mismatch')->where('connections.1.remote_account_id', null));
});

test('matching active Instagram identity is visible without leaking another workspace', function () {
    BrandProfile::factory()->create(['workspace_id' => $this->workspace->id, 'instagram_username' => 'neopolis_infra']);
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'handle' => '@NEOPOLIS_INFRA', 'remote_account_id' => 'correct']);
    ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'handle' => '@neopolis_infra', 'remote_account_id' => 'foreign']);
    $this->actingAs($this->owner)->get(route('brands.index'))->assertInertia(fn (Assert $page) => $page
        ->where('connections.0.status', 'connected')->where('connections.0.remote_account_id', 'correct'));
});

test('expired connection requires attention', function () {
    BrandProfile::factory()->create(['workspace_id' => $this->workspace->id, 'x_username' => 'neopolisinfra']);
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'handle' => '@neopolisinfra', 'token_expires_at' => now()->subMinute()]);
    $this->actingAs($this->owner)->get(route('brands.index'))->assertInertia(fn (Assert $page) => $page->where('connections.2.status', 'needs_attention'));
});

test('brand website must use HTTPS', function (string $url) {
    $this->actingAs($this->owner)->patch(route('brands.update'), ['website_url' => $url])->assertSessionHasErrors('website_url');
})->with(['http://example.com', 'javascript:alert(1)', 'invalid']);

test('brand handles and page identifiers are validated', function () {
    $this->actingAs($this->owner)->patch(route('brands.update'), [
        'website_url' => 'https://example.com', 'instagram_username' => '@invalid', 'facebook_page_id' => 'page-id', 'x_username' => 'too_long_for_x_handle', 'netlify_site_id' => 'not-a-site-id',
    ])->assertSessionHasErrors(['instagram_username', 'facebook_page_id', 'x_username', 'netlify_site_id']);
});

test('changing and reverting the website destination cannot restore blog approval', function () {
    $this->workspace->forceFill(['requires_post_approval' => true])->save();
    BrandProfile::factory()->create(['workspace_id' => $this->workspace->id, 'website_url' => 'https://original.example']);
    $blog = BlogDraft::factory()->create(['workspace_id' => $this->workspace->id, 'author_id' => $this->owner->id]);
    $revision = app(BlogDraftService::class)->revision($blog);
    $blog->forceFill(['review_requested_revision' => $revision, 'review_requested_at' => now(), 'approved_revision' => $revision, 'approved_by' => $this->owner->id, 'approved_at' => now()])->save();

    $this->actingAs($this->owner)->patch(route('brands.update'), ['website_url' => 'https://changed.example'])->assertRedirect();
    $this->patch(route('brands.update'), ['website_url' => 'https://original.example'])->assertRedirect();

    expect($blog->fresh()->approved_revision)->toBeNull()
        ->and($blog->fresh()->review_requested_revision)->toBeNull()
        ->and($blog->fresh()->content_revision)->toBe(3);
    $this->get(route('blogs.index'))->assertInertia(fn (Assert $page) => $page->where('blogs.data.0.status', 'draft'));
});

test('proposed posting dates stay private and follow the brand timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02T23:45:00+05:30'));
    $this->actingAs($this->owner)->post(route('brands.bootstrap'))->assertRedirect();
    $posts = Post::withoutGlobalScopes()->where('workspace_id', $this->owner->current_workspace_id)->orderBy('planned_schedule_at')->get();

    expect($posts)->toHaveCount(7)
        ->and($posts->first()->planned_schedule_at->toIso8601String())->toBe('2026-10-03T04:30:00+00:00')
        ->and($posts->last()->planned_schedule_at->toIso8601String())->toBe('2026-10-09T04:30:00+00:00')
        ->and($posts->every(fn (Post $post): bool => $post->scheduled_at === null && $post->status->value === 'draft' && $post->targets()->count() === 0))->toBeTrue();
});

test('brand setup keeps an existing starter article without duplicating its slug', function () {
    $this->workspace->update(['name' => 'Neopolis']);
    $existing = BlogDraft::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->owner->id,
        'slug' => config('brands.templates.0.blog.slug'),
        'body' => 'Previously edited owner content.',
    ]);

    $this->actingAs($this->owner)->post(route('brands.bootstrap'))->assertRedirect();

    expect(BlogDraft::withoutGlobalScopes()->count())->toBe(2)
        ->and($existing->fresh()->body)->toBe('Previously edited owner content.');
});
