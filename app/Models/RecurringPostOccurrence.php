<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringPostOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $recurring_post_series_id
 * @property string $occurrence_key
 * @property CarbonImmutable $intended_at
 * @property string|null $post_id
 * @property string $status
 */
#[Fillable(['workspace_id', 'recurring_post_series_id', 'occurrence_key', 'intended_at', 'post_id', 'status'])]
class RecurringPostOccurrence extends Model
{
    /** @use HasFactory<RecurringPostOccurrenceFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected function casts(): array
    {
        return [
            'intended_at' => 'immutable_datetime',
        ];
    }
}
