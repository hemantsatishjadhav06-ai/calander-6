<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentIdeaBoardController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = (string) $request->user()->current_workspace_id;
        abort_unless($workspaceId !== '' && $request->user()->hasAllPermissions(['workspace.read'], $workspaceId), 403);
        $ideas = ContentIdea::query()->where('workspace_id', $workspaceId)->orderBy('position')->orderBy('id')->get();

        return Inertia::render('content/board', ['workspaceId' => $workspaceId,
            'canManage' => $request->user()->can('create', Post::class),
            'ideas' => $ideas->map(fn (ContentIdea $idea): array => [...$idea->only(['id', 'title', 'brief', 'caption', 'category', 'tags', 'status', 'template_id', 'draft_post_id', 'creator_project_id', 'revision', 'position']), 'due_on' => $idea->due_on?->format('Y-m-d'), 'post_url' => $idea->draft_post_id ? route('posts.show', $idea->draft_post_id) : null])->all(),
            'templateOptions' => ContentTemplate::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray(),
        ]);
    }

    public function move(Request $request, ContentIdea $contentIdea): JsonResponse
    {
        abort_unless($request->user()->can('create', Post::class), 403);
        $workspaceId = (string) $request->user()->current_workspace_id;
        $data = $request->validate(['expected_workspace_id' => ['required', 'uuid', Rule::in([$workspaceId])], 'expected_revision' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['inbox', 'planned', 'drafted', 'archived'])], 'before_id' => ['nullable', 'uuid', Rule::exists('content_ideas', 'id')->where('workspace_id', $workspaceId)]]);
        DB::transaction(function () use ($workspaceId, $contentIdea, $data): void {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $idea = ContentIdea::query()->where('workspace_id', $workspaceId)->lockForUpdate()->findOrFail($contentIdea->id);
            abort_unless($idea->revision === (int) $data['expected_revision'], 409, 'This idea moved or changed. Reload the board before moving it.');
            abort_if($idea->draft_post_id !== null && ! in_array($data['status'], ['drafted', 'archived'], true), 422, 'A converted idea can be drafted or archived. Its draft remains available.');
            abort_if($idea->draft_post_id === null && $data['status'] === 'drafted', 422, 'Create a reviewable draft before moving an idea to Drafted.');
            $beforeId = $data['before_id'] ?? null;
            abort_if($beforeId === $idea->id, 422, 'An idea cannot be placed before itself.');
            $lane = ContentIdea::query()->where('workspace_id', $workspaceId)->where('status', $data['status'])->whereKeyNot($idea->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            abort_if($beforeId !== null && ! $lane->contains('id', $beforeId), 409, 'The destination changed. Reload the board before moving the idea.');
            $ordered = $lane->all();
            $offset = $beforeId === null ? count($ordered) : (int) $lane->search(fn (ContentIdea $item): bool => $item->id === $beforeId);
            array_splice($ordered, $offset, 0, [$idea]);
            foreach ($ordered as $position => $item) {
                if ($item->position !== $position || $item->id === $idea->id) {
                    $item->forceFill(['position' => $position, 'status' => $data['status'], 'revision' => $item->revision + 1])->save();
                }
            }
        });

        return response()->json(['moved' => true]);
    }
}
