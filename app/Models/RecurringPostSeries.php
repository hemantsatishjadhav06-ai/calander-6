<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\RecurringPostSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $source_post_id
 * @property string|null $editorial_queue_id
 * @property string $name
 * @property string $timezone
 * @property string $frequency
 * @property int $interval
 * @property string $starts_on
 * @property string $next_date
 * @property string $local_time
 * @property string|null $ends_on
 * @property int|null $max_occurrences
 * @property int $lead_hours
 * @property int $generated_count
 * @property string $state
 * @property int $revision
 * @property string|null $last_error
 */
#[Fillable(['workspace_id', 'source_post_id', 'editorial_queue_id', 'name', 'timezone', 'frequency', 'interval', 'starts_on', 'next_date', 'local_time', 'ends_on', 'max_occurrences', 'lead_hours', 'generated_count', 'state', 'revision', 'last_error'])]
class RecurringPostSeries extends Model
{
    /** @use HasFactory<RecurringPostSeriesFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected function casts(): array
    {
        return [
            'interval' => 'integer',
            'max_occurrences' => 'integer',
            'lead_hours' => 'integer',
            'generated_count' => 'integer',
            'revision' => 'integer',
        ];
    }
}
