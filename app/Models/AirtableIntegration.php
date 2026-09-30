<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $configured_by_id
 * @property bool $enabled
 * @property string $base_id
 * @property string $table_id
 * @property string|null $post_name_field
 * @property string|null $interface_url
 * @property int $sync_interval_minutes
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $next_sync_at
 * @property CarbonImmutable|null $cooldown_until
 * @property CarbonImmutable|null $lease_until
 * @property string|null $last_error
 * @property string|null $quota_month
 * @property int $api_calls
 */
class AirtableIntegration extends Model
{
    use HasUuids;

    #[Override]
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'api_calls' => 'integer',
            'last_synced_at' => 'immutable_datetime',
            'next_sync_at' => 'immutable_datetime',
            'cooldown_until' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
        ];
    }
}
