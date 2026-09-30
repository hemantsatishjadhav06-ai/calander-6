<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\CreatorLibraryRequest;
use App\Http\Requests\Creator\StoreCreatorAssetRequest;
use App\Models\CreatorAsset;
use App\Services\Creator\CreatorAssetStorage;
use App\Support\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreatorAssetController extends Controller
{
    public function index(CreatorLibraryRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', CreatorAsset::class), 403);
        $search = trim((string) $request->validated('search', ''));
        $assets = CreatorAsset::query()->where('workspace_id', $request->user()->current_workspace_id)->whereNull('archived_at')
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(50, ['*'], 'cursor', $request->validated('cursor'));

        return response()->json(['workspace_id' => $request->user()->current_workspace_id, 'assets' => $assets->getCollection()->map(fn (CreatorAsset $asset): array => $asset->toView())->all(),
            'next_cursor' => $assets->nextCursor()?->encode()]);
    }

    public function store(StoreCreatorAssetRequest $request, CreatorAssetStorage $storage): JsonResponse
    {
        $asset = $storage->store((string) $request->user()->current_workspace_id, $request->file('file'),
            $request->validated('name'), $request->validated('kind'), $request->user()->id);

        return response()->json(['asset' => $asset->toView()], 201);
    }

    public function content(Request $request, CreatorAsset $creatorAsset): StreamedResponse
    {
        abort_unless($request->user()->can('view', $creatorAsset), 403);
        $storage = FileStorage::disk($creatorAsset->disk);
        abort_unless($storage->exists($creatorAsset->path), 404);

        return $storage->response($creatorAsset->path, null, ['Cache-Control' => 'private, max-age=300',
            'Content-Type' => $creatorAsset->mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
