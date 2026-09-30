<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $post_target_id
 * @property string $revision
 * @property string $text
 * @property string $status
 * @property int $attempts
 * @property string|null $error_message
 * @property string|null $remote_id
 * @property CarbonImmutable|null $attempted_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 */
#[Fillable(['post_target_id', 'revision', 'text', 'status', 'attempts', 'error_message', 'remote_id', 'attempted_at', 'next_attempt_at', 'sent_at'])]
class FirstCommentDelivery extends Model
{
    use HasUuids;

    #[Override]
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'attempted_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<PostTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
