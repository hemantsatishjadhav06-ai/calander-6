<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $workspace_id
 * @property string $post_id
 * @property string|null $client_user_id
 * @property int $policy_version
 * @property string $mode
 * @property string|null $revision
 * @property array<string, array{internal: string, client: string}> $target_states
 * @property bool $on_hold
 */
class PostReviewState extends Model
{
    use HasUuids;

    #[Override]
    protected $guarded = [];

    #[Override]
    protected $attributes = ['policy_version' => 0, 'on_hold' => false, 'target_states' => '{}'];

    #[Override]
    protected function casts(): array
    {
        return ['policy_version' => 'integer', 'target_states' => 'array', 'on_hold' => 'boolean'];
    }
}
