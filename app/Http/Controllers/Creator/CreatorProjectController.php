<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\CreatorLibraryRequest;
use App\Http\Requests\Creator\SaveCreatorProjectRequest;
use App\Models\CreatorAsset;
use App\Models\CreatorProject;
use App\Services\Creator\CreatorProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorProjectController extends Controller
{
    public function index(CreatorLibraryRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', CreatorProject::class), 403);
        $search = trim((string) $request->validated('search', ''));
        $projects = CreatorProject::query()->where('workspace_id', $request->user()->current_workspace_id)
            ->select(['id', 'workspace_id', 'name', 'revision', 'updated_at', 'created_at'])
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(40, ['*'], 'cursor', $request->validated('cursor'));

        return response()->json(['workspace_id' => $request->user()->current_workspace_id, 'projects' => $projects->getCollection()->map(fn (CreatorProject $project): array => ['id' => $project->id, 'workspace_id' => $project->workspace_id,
            'name' => $project->name, 'revision' => $project->revision, 'updated_at' => $project->updated_at?->toISOString()])->all(),
            'next_cursor' => $projects->nextCursor()?->encode()]);
    }

    public function show(Request $request, CreatorProject $creatorProject): JsonResponse
    {
        abort_unless($request->user()->can('view', $creatorProject), 403);

        $ids = collect($creatorProject->document['slides'])->flatMap(fn (array $slide): array => $slide['layers'])
            ->where('type', 'image')->pluck('asset_id')->unique()->values()->all();
        $assets = CreatorAsset::withoutGlobalScopes()->where('workspace_id', $creatorProject->workspace_id)->whereIn('id', $ids)->get();

        return response()->json(['workspace_id' => $creatorProject->workspace_id, 'project' => $creatorProject->toView(),
            'assets' => $assets->map(fn (CreatorAsset $asset): array => $asset->toView())->all()]);
    }

    public function store(SaveCreatorProjectRequest $request, CreatorProjectService $projects): JsonResponse
    {
        return response()->json(['project' => $projects->create($request->user(), $request->validated())->toView()], 201);
    }

    public function update(SaveCreatorProjectRequest $request, CreatorProject $creatorProject, CreatorProjectService $projects): JsonResponse
    {
        return response()->json(['project' => $projects->update($creatorProject, $request->validated())->toView()]);
    }
}
