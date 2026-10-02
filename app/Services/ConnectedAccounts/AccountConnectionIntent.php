<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AccountConnectionIntent
{
    /** @return array{user_id: string, workspace_id: string, state: string, created_at: int} */
    public function create(Request $request, string $state): array
    {
        $user = $request->user();

        if ($user === null || ! is_string($user->current_workspace_id) || $user->current_workspace_id === '' || $state === '') {
            throw new RuntimeException('An account connection requires a user, workspace, and OAuth state.');
        }

        return [
            'user_id' => (string) $user->id,
            'workspace_id' => $user->current_workspace_id,
            'state' => $state,
            'created_at' => now()->getTimestamp(),
        ];
    }

    public function rememberRedirect(Request $request, string $flow, Response $response): void
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? null;

        if (! is_string($state) || $state === '') {
            throw new RuntimeException('The OAuth provider did not include a connection state.');
        }

        $request->session()->put('accounts.connection_intents.'.$flow, $this->create($request, $state));
    }

    /** @return array{user_id: string, workspace_id: string, state: string, created_at: int}|null */
    public function current(Request $request, string $flow): ?array
    {
        $intent = $request->session()->get('accounts.connection_intents.'.$flow);

        if (! $this->isValid($request, $intent)) {
            return null;
        }

        /** @var array{user_id: string, workspace_id: string, state: string, created_at: int} $intent */
        return $intent;
    }

    public function callbackMatches(Request $request, string $flow): bool
    {
        $intent = $this->current($request, $flow);
        $state = $request->query('state');

        return $intent !== null && is_string($state) && hash_equals($intent['state'], $state);
    }

    public function isValid(Request $request, mixed $intent): bool
    {
        $user = $request->user();

        return $user !== null
            && is_array($intent)
            && ($intent['user_id'] ?? null) === (string) $user->id
            && ($intent['workspace_id'] ?? null) === $user->current_workspace_id
            && is_string($intent['state'] ?? null)
            && $intent['state'] !== ''
            && is_int($intent['created_at'] ?? null)
            && $intent['created_at'] <= now()->getTimestamp()
            && $intent['created_at'] >= now()->subMinutes(30)->getTimestamp();
    }
}
