<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\ContentTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property int $revision
 * @property string $name
 * @property string|null $description
 * @property string $brief
 * @property string|null $caption
 * @property list<string> $hashtags
 * @property string|null $first_comment
 * @property array{kind: string, ids?: list<string>}|null $destination
 * @property list<string>|null $media_asset_ids
 * @property array<string, mixed>|null $document
 * @property string|null $source_project_id
 * @property int|null $source_project_revision
 * @property CarbonImmutable|null $archived_at
 */
#[Fillable(['workspace_id', 'created_by_id', 'name', 'description', 'brief', 'caption', 'hashtags', 'first_comment', 'document', 'source_project_id', 'source_project_revision', 'revision', 'archived_at', 'destination', 'media_asset_ids'])]
class ContentTemplate extends Model
{
    /** @use HasFactory<ContentTemplateFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['destination' => 'array', 'media_asset_ids' => 'array', 'revision' => 'integer', 'hashtags' => 'array', 'document' => 'array', 'source_project_revision' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
