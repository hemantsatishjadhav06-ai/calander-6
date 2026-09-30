<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property CarbonImmutable|null $updated_at
 * @property string $post_id
 * @property string|null $project_id
 * @property int $project_revision
 * @property string $request_hash
 * @property list<string> $media_ids
 * @property array<string, mixed> $document_snapshot
 */
#[Fillable(['workspace_id', 'post_id', 'project_id', 'project_revision', 'idempotency_key', 'request_hash', 'document_snapshot', 'media_ids'])]
class CreatorExportBatch extends Model
{
    use HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['project_revision' => 'integer', 'document_snapshot' => 'array', 'media_ids' => 'array'];
    }
}
