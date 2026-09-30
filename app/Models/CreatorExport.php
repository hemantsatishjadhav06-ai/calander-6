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
 * @property string|null $project_id
 * @property string|null $batch_id
 * @property string $post_media_id
 * @property int $project_revision
 * @property string $slide_id
 * @property string $sha256
 */
#[Fillable(['workspace_id', 'project_id', 'batch_id', 'post_media_id', 'project_revision', 'slide_id', 'sha256'])]
class CreatorExport extends Model
{
    use HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['project_revision' => 'integer'];
    }
}
