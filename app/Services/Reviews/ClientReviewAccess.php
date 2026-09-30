<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Http\Request;

class ClientReviewAccess
{
    public function isClient(?User $user): bool
    {
        return $user?->getMembershipForWorkspace($user->current_workspace_id)?->role === WorkspaceRole::Client;
    }

    /** @return array<string, mixed> */
    public function shared(Request $request): array
    {
        return [
            'name' => config('app.name'),
            'auth' => ['user' => $request->user()?->only(['id', 'name', 'email', 'avatar'])],
            'flash' => [
                'success' => $request->hasSession() ? $request->session()->get('success') : null,
                'error' => $request->hasSession() ? $request->session()->get('error') : null,
            ],
        ];
    }
}
