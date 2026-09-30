<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reviews;

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\AssignClientReviewerRequest;
use App\Http\Requests\Reviews\ConfigureReviewWorkflowRequest;
use App\Http\Requests\Reviews\ReviewActionRequest;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostReviewState;
use App\Models\PostTarget;
use App\Models\PostWorkflowEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\PostReviewService;
use App\Services\Publishing\FirstCommentService;
use App\Services\Publishing\SegmentMediaResolver;
use App\Services\Reviews\ClientReviewAccess;
use App\Services\Reviews\StagedPostReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReviewWorkflowController extends Controller
{
    public function index(Request $request, StagedPostReviewService $staged, PostReviewService $reviews): Response
    {
        $actor = $request->user();
        $workspaceId = $actor->current_workspace_id;
        abort_unless($actor->hasSomePermissions(['workspace.read', 'workspace.review.client'], $workspaceId), 403);
        $isClient = app(ClientReviewAccess::class)->isClient($actor);
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $query = Post::query()->where('workspace_id', $workspaceId)
            ->whereIn('status', [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed, PostStatus::Missed])
            ->with(['targets.account', 'targets.placements', 'media'])->latest();
        if ($isClient) {
            $query->whereIn('id', PostReviewState::query()->where('workspace_id', $workspaceId)->where('client_user_id', $actor->id)->select('post_id'));
        }
        $selectedId = $request->string('post')->toString();
        if ($selectedId !== '') {
            $post = (clone $query)->whereKey($selectedId)->firstOrFail();
            $this->authorizePost($actor, $post, $staged);
            $query->whereKey($selectedId);
        }
        $page = $query->paginate(20)->withQueryString();
        $visible = $page->getCollection()->filter(fn (Post $post): bool => ! $isClient || $staged->canClientView($post, $actor));
        $presented = $visible->map(fn (Post $post): array => $this->present($post, $actor, $staged, $reviews))->values()->all();

        return Inertia::render($isClient ? 'reviews/client' : 'reviews/index', [
            'workspaceName' => $workspace->name,
            'workspaceId' => $workspaceId,
            'reviewWorkspaces' => $isClient ? $actor->workspaceMemberships()->with('workspace')->get()->map(fn (WorkspaceMembership $membership): array => ['id' => $membership->workspace_id, 'name' => $membership->workspace->name])->values()->all() : [],
            'mode' => (string) $workspace->getAttribute('review_mode'),
            'isClient' => $isClient,
            'canManage' => $actor->hasAllPermissions(['workspace.settings.manage'], $workspaceId),
            'posts' => [...$page->toArray(), 'data' => $presented],
            'clients' => $isClient ? [] : WorkspaceMembership::query()->where('workspace_id', $workspaceId)->where('role', WorkspaceRole::Client->value)
                ->with('user')->get()->map(fn (WorkspaceMembership $membership): array => ['id' => $membership->user_id, 'name' => $membership->user->name])->values()->all(),
            'urls' => ['configure' => route('reviews.configure'), 'index' => route('reviews.index'), 'logout' => route('logout'), 'switchWorkspace' => route('workspaces.switch'), 'members' => $isClient ? null : route('settings.workspace.members')],
        ]);
    }

    public function configure(ConfigureReviewWorkflowRequest $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id;
        DB::transaction(function () use ($request, $workspaceId): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspaceId);
            $mode = $request->validated('mode');
            if ($workspace->getAttribute('review_mode') === $mode) {
                return;
            }
            $workspace->forceFill(['review_mode' => $mode, 'review_policy_version' => ((int) $workspace->getAttribute('review_policy_version')) + 1])->save();
            PostWorkflowEvent::create([
                'workspace_id' => $workspaceId, 'post_id' => null, 'actor_id' => $request->user()->id,
                'source' => 'reviews', 'action' => 'workflow_configured', 'revision' => hash('sha256', $mode),
                'note' => $mode, 'audience' => 'internal',
            ]);
        });

        return back()->with('success', 'Review workflow updated. Existing unpublished posts follow this policy.');
    }

    public function act(ReviewActionRequest $request, Post $post, StagedPostReviewService $staged, PostReviewService $reviews): RedirectResponse
    {
        $this->authorizePost($request->user(), $post, $staged);
        $data = $request->validated();
        if ($staged->mode($post) === 'off') {
            abort_unless(in_array($data['action'], ['submit', 'approve', 'request_changes'], true), 422, 'Enable staged reviews to use comments and holds.');
            $reviews->act($post, $request->user(), $data['action'], $data['revision'], $data['note'] ?? null, 'reviews');
        } else {
            $staged->act($post, $request->user(), $data['action'], $data['revision'], $data['note'] ?? null, 'reviews', $data['target_id'] ?? null, $data['stage']);
        }

        return back()->with('success', 'Review updated.');
    }

    public function assign(AssignClientReviewerRequest $request, Post $post, StagedPostReviewService $staged): RedirectResponse
    {
        $this->authorizePost($request->user(), $post, $staged);
        $staged->assignClient($post, $request->user(), $request->validated('client_user_id'), $request->validated('revision'));

        return back()->with('success', 'Client reviewer updated. Client approval is required again.');
    }

    public function history(Request $request, Post $post, StagedPostReviewService $staged): JsonResponse
    {
        $this->authorizePost($request->user(), $post, $staged);

        return response()->json($this->events($post, app(ClientReviewAccess::class)->isClient($request->user())));
    }

    private function authorizePost(User $actor, Post $post, StagedPostReviewService $staged): void
    {
        abort_unless($post->workspace_id === $actor->current_workspace_id, 404);
        if (app(ClientReviewAccess::class)->isClient($actor)) {
            abort_unless($staged->canClientView($post, $actor), 404);
        } else {
            abort_unless($actor->hasAllPermissions(['workspace.read'], $post->workspace_id), 403);
        }
    }

    /** @return array<string, mixed> */
    private function present(Post $post, User $actor, StagedPostReviewService $staged, PostReviewService $reviews): array
    {
        $state = $staged->state($post);
        $isClient = app(ClientReviewAccess::class)->isClient($actor);
        $status = $reviews->status($post);

        return [
            'id' => $post->id,
            'base_text' => $post->base_text,
            'revision' => $reviews->revision($post),
            'review_status' => $status,
            'publishing_status' => $post->status->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'client_user_id' => $state?->client_user_id,
            'on_hold' => $state->on_hold ?? false,
            'targets' => $post->targets->map(fn (PostTarget $target): array => [
                'id' => $target->id, 'platform' => $target->platform->value, 'handle' => $target->account?->handle,
                'sections' => $target->sections, 'format' => $target->format->value,
                'first_comment' => [
                    'enabled' => app(FirstCommentService::class)->enabled($post, $target),
                    'text' => app(FirstCommentService::class)->text($post, $target),
                    ...app(FirstCommentService::class)->capability($target),
                ],
                'media_by_section' => $this->mediaBySection($post, $target),
                'placements' => $target->placements->map(fn (PostMediaPlacement $placement): array => ['media_id' => $placement->post_media_id, 'segment_ref' => $placement->segment_ref, 'position' => $placement->position])->values()->all(),
                'internal_status' => in_array($status, ['stale', 'draft'], true) ? 'pending' : ($state?->target_states[$target->id]['internal'] ?? 'pending'),
                'client_status' => in_array($status, ['stale', 'draft'], true) ? 'pending' : ($state?->target_states[$target->id]['client'] ?? 'pending'),
            ])->values()->all(),
            'media' => $post->media->map(fn (PostMedia $media): array => ['id' => $media->id, 'url' => $media->url(), 'kind' => $media->kind, 'alt_text' => $media->alt_text, 'position' => $media->position])->values()->all(),
            'history' => $this->events($post, $isClient, true),
            'urls' => ['act' => route('reviews.act', $post), 'assign' => route('reviews.assign', $post), 'history' => route('reviews.history', $post), 'open' => route('reviews.index', ['post' => $post->id]), 'edit' => $isClient ? null : route('posts.show', $post)],
        ];
    }

    /** @return array<int, list<string>> */
    private function mediaBySection(Post $post, PostTarget $target): array
    {
        $resolved = app(SegmentMediaResolver::class)->resolve(
            $target->sections,
            $target->section_sources ?? [],
            $target->segment_breaks ?? [],
            array_values($target->placements->map(fn (PostMediaPlacement $placement): array => [
                'post_media_id' => $placement->post_media_id, 'segment_ref' => $placement->segment_ref, 'position' => $placement->position,
            ])->all()),
            array_values($post->media->all()),
        );

        return array_map(fn (array $media): array => array_map(fn (PostMedia $item): string => $item->id, $media), $resolved);
    }

    /** @return array<string, mixed> */
    private function events(Post $post, bool $isClient, bool $firstPage = false): array
    {
        $query = PostWorkflowEvent::query()->where('post_workflow_events.workspace_id', $post->workspace_id)->where('post_id', $post->id)
            ->leftJoin('users', 'users.id', '=', 'post_workflow_events.actor_id')
            ->when($isClient, fn ($query) => $query->where('audience', 'client'))
            ->orderByDesc('post_workflow_events.created_at')->orderByDesc('post_workflow_events.id');
        $events = $query->paginate(10, ['post_workflow_events.id', 'action', 'stage', 'note', 'revision', 'review_target_id', 'users.name as actor_name', 'post_workflow_events.created_at'], 'page', $firstPage ? 1 : null);
        $events->withPath(route('reviews.history', $post));

        return $events->toArray();
    }
}
