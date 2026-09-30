<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\CreatorAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $created_by_id
 * @property CarbonImmutable|null $updated_at
 * @property string $name
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string|null $folder
 * @property list<string>|null $tags
 * @property bool $starred
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property int $revision
 * @property int $version
 * @property string|null $root_asset_id
 * @property string|null $parent_asset_id
 * @property string $sha256
 */
#[Fillable(['workspace_id', 'created_by_id', 'name', 'kind', 'disk', 'path', 'mime', 'size_bytes', 'width', 'height', 'sha256', 'folder', 'tags', 'starred', 'archived_at', 'revision', 'version', 'root_asset_id', 'parent_asset_id'])]
class CreatorAsset extends Model
{
    /** @use HasFactory<CreatorAssetFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'image', 'revision' => 1, 'version' => 1, 'starred' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size_bytes' => 'integer', 'revision' => 'integer', 'version' => 'integer', 'tags' => 'array', 'starred' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    /** @return array<string, mixed> */
    public function toView(): array
    {
        return ['id' => $this->id, 'workspace_id' => $this->workspace_id, 'name' => $this->name, 'kind' => $this->kind,
            'mime' => $this->mime, 'size_bytes' => $this->size_bytes, 'width' => $this->width,
            'height' => $this->height, 'content_url' => route('creator.assets.content', $this),
            'folder' => $this->folder, 'tags' => $this->tags ?? [], 'starred' => $this->starred,
            'archived_at' => $this->archived_at?->toIso8601String(), 'revision' => $this->revision, 'version' => $this->version,
            'root_asset_id' => $this->root_asset_id, 'parent_asset_id' => $this->parent_asset_id, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
