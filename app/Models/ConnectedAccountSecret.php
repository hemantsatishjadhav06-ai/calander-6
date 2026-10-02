<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Posts\PostApprovalService;
use Database\Factories\ConnectedAccountSecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $connected_account_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $app_password
 * @property array<string, mixed>|null $session
 */
#[Fillable([
    'connected_account_id',
    'access_token',
    'refresh_token',
    'app_password',
    'session',
])]
#[WithoutIncrementing]
class ConnectedAccountSecret extends Model
{
    /** @use HasFactory<ConnectedAccountSecretFactory> */
    use HasFactory;

    #[Override]
    protected $primaryKey = 'connected_account_id';

    #[Override]
    protected $keyType = 'string';

    #[Override]
    protected static function booted(): void
    {
        static::updating(function (ConnectedAccountSecret $secret): void {
            if ($secret->isDirty('session') && PostApprovalService::destinationTransport($secret->getOriginal('session')) !== PostApprovalService::destinationTransport($secret->session)) {
                Post::withoutGlobalScopes()->whereHas('targets', fn (Builder $query): Builder => $query->where('connected_account_id', $secret->connected_account_id))
                    ->update(PostApprovalService::clearedReview());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'app_password' => 'encrypted',
            'session' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }
}
