<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpdateContentAssetRequest;
use App\Models\CreatorAsset;
use App\Models\Post;
use App\Services\Creator\CreatorAssetLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentAssetLibraryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('viewAny', CreatorAsset::class), 403);
        $workspaceId = (string) $request->user()->current_workspace_id;
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'folder' => ['nullable', 'string', 'max:100'], 'tag' => ['nullable', 'string', 'max:60'], 'starred' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean']]);
        $query = CreatorAsset::query()->where('workspace_id', $workspaceId);
        $options = (clone $query)->get(['folder', 'tags']);
        $filters = ['q' => trim((string) ($filters['q'] ?? '')), 'folder' => $filters['folder'] ?? '', 'tag' => $filters['tag'] ?? '', 'starred' => (bool) ($filters['starred'] ?? false), 'archived' => (bool) ($filters['archived'] ?? false)];

        return Inertia::render('content/library', [
            'workspaceId' => $workspaceId,
            'canManage' => $request->user()->can('create', Post::class),
            'assets' => $query->when($filters['q'] !== '', fn ($builder) => $builder->whereLike('name', '%'.$filters['q'].'%'))
                ->when($filters['folder'] !== '', fn ($builder) => $builder->where('folder', $filters['folder']))
                ->when($filters['tag'] !== '', fn ($builder) => $builder->whereJsonContains('tags', $filters['tag']))
                ->when($filters['starred'], fn ($builder) => $builder->where('starred', true))
                ->when($filters['archived'], fn ($builder) => $builder->whereNotNull('archived_at'), fn ($builder) => $builder->whereNull('archived_at'))
                ->latest()->orderByDesc('id')->paginate(24)->withQueryString()->through(fn (CreatorAsset $asset): array => $asset->toView()),
            'filters' => $filters,
            'folders' => $options->pluck('folder')->filter()->unique()->sort()->values()->all(),
            'tags' => $options->flatMap(fn (CreatorAsset $asset): array => $asset->tags ?? [])->unique()->sort()->values()->all(),
        ]);
    }

    public function update(UpdateContentAssetRequest $request, CreatorAsset $creatorAsset, CreatorAssetLibraryService $library): JsonResponse
    {
        return response()->json(['asset' => $library->update($request->user(), $creatorAsset, $request->validated())->toView()]);
    }

    public function versions(Request $request, CreatorAsset $creatorAsset): JsonResponse
    {
        abort_unless($request->user()->can('view', $creatorAsset), 403);
        $rootId = $creatorAsset->root_asset_id ?? $creatorAsset->id;
        $assets = CreatorAsset::query()->where('workspace_id', $request->user()->current_workspace_id)
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_asset_id', $rootId))->orderByDesc('version')->get();

        return response()->json(['assets' => $assets->map(fn (CreatorAsset $asset): array => $asset->toView())->all()]);
    }

    public function restore(Request $request, CreatorAsset $creatorAsset, CreatorAssetLibraryService $library): JsonResponse
    {
        $data = $this->validateVersion($request);

        return response()->json(['asset' => $library->version($request->user(), $creatorAsset, (int) $data['expected_revision'])->toView()], 201);
    }

    public function uploadVersion(Request $request, CreatorAsset $creatorAsset, CreatorAssetLibraryService $library): JsonResponse
    {
        $data = $this->validateVersion($request);
        $request->validate(['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:8192']]);

        return response()->json(['asset' => $library->version($request->user(), $creatorAsset, (int) $data['expected_revision'], $request->file('file'))->toView()], 201);
    }

    /** @return array<string, mixed> */
    private function validateVersion(Request $request): array
    {
        abort_unless($request->user()->can('create', Post::class), 403);

        return $request->validate(['expected_workspace_id' => ['required', 'uuid', Rule::in([(string) $request->user()->current_workspace_id])], 'expected_revision' => ['required', 'integer', 'min:1']]);
    }
}
