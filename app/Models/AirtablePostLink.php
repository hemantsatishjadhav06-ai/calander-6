<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $integration_id
 * @property string|null $post_id
 * @property string $app_post_id
 * @property string|null $record_id
 * @property string|null $export_hash
 * @property string|null $proposal_hash
 * @property array{text: string, revision: string, hash: string}|null $conflict
 * @property string|null $sync_error
 */
class AirtablePostLink extends Model
{
    use HasUuids;

    #[Override]
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return ['conflict' => 'array'];
    }
}
