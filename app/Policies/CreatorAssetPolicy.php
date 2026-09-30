<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CreatorAsset;
use App\Models\User;

class CreatorAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_workspace_id !== null
            && $user->hasAllPermissions(['workspace.read'], $user->current_workspace_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, CreatorAsset $asset): bool
    {
        return $this->viewAny($user) && $asset->workspace_id === $user->current_workspace_id;
    }
}
