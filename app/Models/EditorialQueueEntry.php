<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\EditorialQueueEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $editorial_queue_id
 * @property string $post_id
 * @property int $priority
 * @property string $status
 * @property string|null $blocked_reason
 * @property CarbonImmutable|null $scheduled_at
 */
#[Fillable(['workspace_id', 'editorial_queue_id', 'post_id', 'priority', 'status', 'blocked_reason', 'scheduled_at'])]
class EditorialQueueEntry extends Model
{
    /** @use HasFactory<EditorialQueueEntryFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'scheduled_at' => 'immutable_datetime',
        ];
    }
}
