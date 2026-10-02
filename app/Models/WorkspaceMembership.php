<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

#[Appends([
    'permissions',
])]
#[Fillable([
    'workspace_id',
    'user_id',
    'role',
])]
class WorkspaceMembership extends Model
{
    /** @use HasFactory<WorkspaceMembershipFactory> */
    use HasFactory, HasUuids;

    #[Override]
    protected static function booted(): void
    {
        // When a user is removed from (or leaves) a workspace, revoke any MCP
        // workspace grants their OAuth tokens hold for it, so a still-valid token
        // can't keep acting in a workspace they no longer belong to.
        static::created(function (WorkspaceMembership $membership): void {
            $workspace = $membership->workspace()->first();
            if ($workspace !== null && $workspace->owner_id === $membership->user_id) {
                $workspace->invalidateContentApprovals();
            }
        });

        static::updating(function (WorkspaceMembership $membership): void {
            if ($membership->isDirty(['role', 'workspace_id', 'user_id'])) {
                $workspace = Workspace::query()->find((string) $membership->getOriginal('workspace_id'));
                if ($workspace !== null && $workspace->owner_id === $membership->getOriginal('user_id')) {
                    $workspace->assertApprovalAuthorityMutable();
                    $workspace->invalidateContentApprovals();
                }
            }
        });

        static::deleting(function (WorkspaceMembership $membership): void {
            $workspace = $membership->workspace()->first();
            if ($workspace !== null && $workspace->owner_id === $membership->user_id) {
                $workspace->assertApprovalAuthorityMutable();
            }
        });

        static::deleted(function (WorkspaceMembership $membership): void {
            $workspace = $membership->workspace()->first();
            if ($workspace !== null && $workspace->owner_id === $membership->user_id) {
                $workspace->invalidateContentApprovals();
            }

            McpGrantWorkspace::query()
                ->where('user_id', $membership->user_id)
                ->where('workspace_id', $membership->workspace_id)
                ->delete();
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionsAttribute(): array
    {
        return $this->role->permissions();
    }

    public function isOwner(): bool
    {
        return $this->role === WorkspaceRole::Owner;
    }
}
