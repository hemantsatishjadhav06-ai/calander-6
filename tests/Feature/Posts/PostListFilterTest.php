<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
});

function makePost(Workspace $w, User $u, PostStatus $status): Post
{
    return Post::factory()->for($w)->create([
        'author_id' => $u->id,
        'status' => $status->value,
    ]);
}

it('lists workspace posts and excludes deleted', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Deleted);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('posts/index')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.status', 'draft')));
});

it('filters by status tab', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Scheduled);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['status' => 'scheduled']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.status', 'scheduled')));
});

it('exposes per-status tab counts that exclude deleted', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Scheduled);
    makePost($this->workspace, $this->user, PostStatus::Deleted);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('counts.all', 3)
            ->where('counts.draft', 2)
            ->where('counts.scheduled', 1)
            ->where('counts.published', 0));
});

it('filters by text query on base_text', function (): void {
    Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id, 'base_text' => 'launch announcement']);
    Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id, 'base_text' => 'weekly recap']);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['q' => 'launch']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.base_text', 'launch announcement')));
});

it('returns matching posts and counts for every platform in a partial reload', function (Platform $platform): void {
    $matches = collect([PostStatus::Draft, PostStatus::Scheduled])->map(function (PostStatus $status) use ($platform): Post {
        $post = makePost($this->workspace, $this->user, $status);
        $account = ConnectedAccount::factory()->for($this->workspace)->create(['platform' => $platform->value]);
        PostTarget::factory()->for($post)->create([
            'connected_account_id' => $account->id,
            'platform' => $platform->value,
        ]);

        return $post;
    });
    makePost($this->workspace, $this->user, PostStatus::Draft);

    $response = $this->actingAs($this->user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'posts/index',
        'X-Inertia-Partial-Data' => 'posts,filters,counts',
        'X-Inertia-Reset' => 'posts',
    ])->get(route('posts.index', ['platform' => $platform->value]));

    $response->assertOk()
        ->assertJsonPath('props.filters.platform', $platform->value)
        ->assertJsonCount(2, 'props.posts.data')
        ->assertJsonPath('props.counts', [
            'all' => 2,
            'scheduled' => 1,
            'draft' => 1,
            'published' => 0,
            'missed' => 0,
        ]);
    expect(array_column($response->json('props.posts.data'), 'id'))->toEqualCanonicalizing($matches->pluck('id')->all());
})->with(Platform::cases());

it('refreshes search and set counts across status changes and clearing filters', function (): void {
    $set = AccountSet::factory()->for($this->workspace)->create();
    foreach ([
        ['launch draft', PostStatus::Draft, $set->id],
        ['launch scheduled', PostStatus::Scheduled, $set->id],
        ['weekly draft', PostStatus::Draft, $set->id],
        ['launch published', PostStatus::Published, null],
        ['launch deleted', PostStatus::Deleted, $set->id],
    ] as [$text, $status, $setId]) {
        Post::factory()->for($this->workspace)->create([
            'author_id' => $this->user->id,
            'base_text' => $text,
            'status' => $status,
            'account_set_id' => $setId,
        ]);
    }

    foreach ([
        [['q' => 'launch', 'set' => $set->id], 2, 1, 1, 0],
        [['q' => 'launch', 'set' => $set->id, 'status' => 'draft'], 1, 1, 1, 0],
        [['q' => 'launch'], 3, 1, 1, 1],
        [['set' => $set->id], 3, 2, 1, 0],
        [[], 4, 2, 1, 1],
    ] as [$filters, $visible, $draft, $scheduled, $published]) {
        $this->actingAs($this->user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'posts/index',
            'X-Inertia-Partial-Data' => 'posts,filters,counts',
            'X-Inertia-Reset' => 'posts',
        ])->get(route('posts.index', $filters))
            ->assertOk()
            ->assertJsonCount($visible, 'props.posts.data')
            ->assertJsonPath('props.filters', [
                'status' => $filters['status'] ?? 'all',
                'set' => $filters['set'] ?? '',
                'platform' => '',
                'q' => $filters['q'] ?? '',
            ])
            ->assertJsonPath('props.counts', [
                'all' => $draft + $scheduled + $published,
                'scheduled' => $scheduled,
                'draft' => $draft,
                'published' => $published,
                'missed' => 0,
            ]);
    }
});
