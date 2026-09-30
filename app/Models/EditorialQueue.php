<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\EditorialQueueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $category
 * @property int $priority
 * @property string $state
 * @property int $revision
 */
#[Fillable(['workspace_id', 'name', 'category', 'priority', 'state', 'revision'])]
class EditorialQueue extends Model
{
    /** @use HasFactory<EditorialQueueFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'revision' => 'integer',
        ];
    }
}
