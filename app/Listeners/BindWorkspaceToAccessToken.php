<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\McpGrantWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\AccessTokenRevoked;

/**
 * Bind an issued token to its exact approved authorization code, or carry forward
 * the validated refresh token's workspace without consuming another consent.
 */
class BindWorkspaceToAccessToken
{
    private const string REFRESH_BINDING = 'mcp_refresh_workspace_binding';

    public function captureRefreshBinding(AccessTokenRevoked $event): void
    {
        if (! $this->isRefreshRequest()) {
            return;
        }

        $binding = McpGrantWorkspace::query()->where('access_token_id', $event->tokenId)->first();

        if ($binding !== null) {
            Context::addHidden(self::REFRESH_BINDING, [
                'user_id' => $binding->user_id,
                'client_id' => $binding->client_id,
                'workspace_id' => $binding->workspace_id,
            ]);
        }
    }

    public function handle(AccessTokenCreated $event): void
    {
        if ($this->isRefreshRequest()) {
            $binding = Context::pullHidden(self::REFRESH_BINDING);

            if (! is_array($binding)
                || $binding['user_id'] !== $event->userId
                || $binding['client_id'] !== $event->clientId
                || ! User::query()->find($event->userId)?->isMemberOfWorkspace($binding['workspace_id'])) {
                return;
            }

            McpGrantWorkspace::create([
                ...$binding,
                'access_token_id' => $event->tokenId,
            ]);

            return;
        }

        $code = request()->input('code');

        if (! request()->isMethod('POST') || ! request()->is('oauth/token')
            || request()->input('grant_type') !== 'authorization_code' || ! is_string($code) || $code === '') {
            return;
        }

        $pending = McpGrantWorkspace::query()
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->whereNull('access_token_id')
            ->where('authorization_code_hash', hash('sha256', $code))
            ->value('id');

        if ($pending === null) {
            return;
        }

        McpGrantWorkspace::where('id', $pending)
            ->whereNull('access_token_id')
            ->update(['access_token_id' => $event->tokenId]);
    }

    private function isRefreshRequest(): bool
    {
        return request()->isMethod('POST')
            && request()->is('oauth/token')
            && request()->input('grant_type') === 'refresh_token';
    }
}
