<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CreatorProject;
use App\Models\User;

class CreatorProjectPolicy
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

    public function view(User $user, CreatorProject $project): bool
    {
        return $this->viewAny($user) && $project->workspace_id === $user->current_workspace_id;
    }

    public function update(User $user, CreatorProject $project): bool
    {
        return $this->view($user, $project);
    }
}
