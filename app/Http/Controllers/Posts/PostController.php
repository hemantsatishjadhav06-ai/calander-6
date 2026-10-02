<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Dto\Post\DraftData;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Models\AccountSet;
use App\Models\Post;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostDeletionService;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PostStaleWriteException;
use App\Support\PostListItem;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(private readonly DraftService $drafts) {}

    public function index(Request $request): Response
    {
        $request->user()->can('viewAny', Post::class) ?: abort(403);

        $status = $request->string('status')->toString();
        $set = $request->string('set')->toString();
        $platform = $request->string('platform')->toString();
        $q = $request->string('q')->toString();

        $sets = AccountSet::query()->get(['id', 'name'])
            ->map(fn (AccountSet $s): array => ['id' => $s->id, 'name' => $s->name])->all();

        // The status-tab counts and the paginated list share the same set/platform/
        // search predicates (only the status filter differs), so define them once to
        // keep the two queries from drifting apart.
        $applyFilters = fn ($query) => $query
            ->when($set !== '', fn ($q2) => $q2->where('account_set_id', $set))
            ->when($platform !== '', fn ($q2) => $q2->whereHas('targets',
                fn ($t) => $t->where('platform', $platform)))
            ->when($q !== '', fn ($q2) => $q2->whereLike('base_text', "%{$q}%"));

        // Per-status tab counts honour the active search/platform/set filters but
        // not the status filter itself, so each tab shows how many posts of that
        // status match what's currently being looked at.
        $byStatus = $applyFilters(
            Post::query()->where('status', '!=', PostStatus::Deleted->value)
        )
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'all' => (int) $byStatus->sum(),
            'scheduled' => (int) ($byStatus[PostStatus::Scheduled->value] ?? 0),
            'draft' => (int) ($byStatus[PostStatus::Draft->value] ?? 0),
            'published' => (int) ($byStatus[PostStatus::Published->value] ?? 0),
            'missed' => (int) ($byStatus[PostStatus::Missed->value] ?? 0),
        ];

        return Inertia::render('posts/index', [
            'posts' => Inertia::scroll(fn () => $applyFilters(
                Post::query()
                    ->with(['author:id,name', 'targets.account.secret', 'targets.placements', 'media', 'workspace'])
                    ->where('status', '!=', PostStatus::Deleted->value)
                    ->when($status !== '' && $status !== 'all', fn ($query) => $query->where('status', $status))
            )
                // Order by the effective timeline date. Cursor pagination cannot
                // build its keyset WHERE clause from a raw orderBy (those orders
                // carry no direction and get stripped, crashing page 2), so the
                // expression is aliased to a real column and ordered by the alias.
                // `id` is the unique tiebreaker keyset pagination requires.
                ->select('posts.*')
                ->selectRaw('COALESCE(scheduled_at, created_at) as sort_key')
                ->orderByDesc('sort_key')
                ->orderByDesc('id')
                ->cursorPaginate(20)
                ->withQueryString()
                // The cursor's keyset is (sort_key, id), so both must survive the
                // item transform — the paginator reads them off each emitted row to
                // encode the next cursor. `id` is already in PostListItem; carry the
                // computed `sort_key` alongside it.
                ->through(fn (Post $post): array => [
                    ...PostListItem::make($post),
                    'sort_key' => $post->getAttribute('sort_key'),
                ]))->defer(),
            'filters' => ['status' => $status ?: 'all', 'set' => $set, 'platform' => $platform, 'q' => $q],
            'sets' => $sets,
            'counts' => $counts,
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->drafts->createDraft(
            $request->user()->current_workspace_id,
            $request->user(),
            $request->validated('destination'),
            array_values(array_map(static fn (mixed $s): string => (string) ($s ?? ''), $request->validated('segments', []))),
            array_values($request->validated('mentions', [])),
            $request->validated('auto_repost'),
            DraftData::fromArray($request->validated()),
        );

        return response()->json(['post' => PostView::make($post->fresh(['targets.account.secret', 'targets.placements', 'media']))], 201);
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        try {
            $updated = $this->drafts->updateDraft($post, DraftData::fromArray($request->validated()));
        } catch (PostStaleWriteException) {
            return response()->json([
                'post' => PostView::make($post->fresh(['targets.account.secret', 'targets.placements', 'media'])),
                'message' => 'stale_write',
            ], 409);
        }

        return response()->json(['post' => PostView::make($updated->fresh(['targets.account.secret', 'targets.placements', 'media']))]);
    }

    public function duplicate(Request $request, Post $post, PostDuplicator $duplicator): RedirectResponse
    {
        $request->user()->can('create', Post::class) ?: abort(403);

        // Only terminal posts are eligible — mirror the client capability model
        // so the endpoint contract can't be bypassed for a draft/scheduled post.
        in_array($post->status, [
            PostStatus::Published, PostStatus::Partial, PostStatus::Failed, PostStatus::Missed,
        ], true) ?: abort(422, 'This post cannot be copied to a draft.');

        $draft = $duplicator->duplicate($post);

        return redirect()->route('posts.show', $draft)->with('success', 'Copied to a new draft.');
    }

    public function destroy(Request $request, Post $post, PostDeletionService $deletion): RedirectResponse
    {
        $request->user()->can('delete', $post) ?: abort(403);

        $needsRemoteCleanup = $deletion->delete($post);

        if (! $needsRemoteCleanup) {
            return redirect()->route('posts.index')->with('success', 'Post deleted.');
        }

        return redirect()->route('posts.index')->with('success', 'Post deleted from connected accounts where possible.');
    }
}
