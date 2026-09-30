<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Models\CreatorAsset;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreatorAssetLibraryService
{
    /** @param array<string, mixed> $data */
    public function update(User $actor, CreatorAsset $asset, array $data): CreatorAsset
    {
        return DB::transaction(function () use ($actor, $asset, $data): CreatorAsset {
            $locked = CreatorAsset::query()->where('workspace_id', $actor->current_workspace_id)->lockForUpdate()->findOrFail($asset->id);
            abort_unless($locked->revision === (int) $data['expected_revision'], 409, 'This asset changed. Reload before saving.');
            $locked->fill(['name' => $data['name'], 'folder' => $data['folder'] ?? null, 'tags' => $data['tags'], 'starred' => $data['starred'], 'archived_at' => $data['archived'] ? ($locked->archived_at ?? now()) : null]);
            $locked->revision++;
            $locked->save();

            return $locked;
        });
    }

    public function version(User $actor, CreatorAsset $source, int $expectedRevision, ?UploadedFile $upload = null): CreatorAsset
    {
        abort_unless($source->workspace_id === $actor->current_workspace_id, 404);
        $created = null;
        try {
            if ($upload !== null) {
                $created = app(CreatorAssetStorage::class)->store($source->workspace_id, $upload, $source->name, $source->kind, $actor->id);
            }

            return DB::transaction(function () use ($actor, $source, $expectedRevision, $created): CreatorAsset {
                $rootId = $source->root_asset_id ?? $source->id;
                CreatorAsset::query()->where('workspace_id', $source->workspace_id)->lockForUpdate()->findOrFail($rootId);
                $locked = CreatorAsset::query()->where('workspace_id', $source->workspace_id)->lockForUpdate()->findOrFail($source->id);
                abort_unless($locked->revision === $expectedRevision, 409, 'This asset changed. Reload before creating a version.');
                abort_unless($created !== null || FileStorage::disk($locked->disk)->exists($locked->path), 422, 'The original image file is unavailable. Upload a new version instead.');
                $version = (int) CreatorAsset::query()->where('workspace_id', $source->workspace_id)->where(function ($query) use ($rootId): void {
                    $query->whereKey($rootId)->orWhere('root_asset_id', $rootId);
                })->max('version') + 1;
                $copy = $created ?? $locked->replicate();
                $copy->fill(['created_by_id' => $actor->id, 'name' => $locked->name, 'folder' => $locked->folder, 'tags' => $locked->tags ?? [], 'starred' => $locked->starred, 'archived_at' => null, 'revision' => 1, 'version' => $version, 'root_asset_id' => $rootId, 'parent_asset_id' => $locked->id]);
                $copy->save();
                $locked->increment('revision');

                return $copy;
            });
        } catch (Throwable $exception) {
            if ($created !== null) {
                FileStorage::disk($created->disk)->delete($created->path);
                $created->delete();
            }
            throw $exception;
        }
    }
}
