<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionIntent;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\BlueskyOAuthConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BlueskyOAuthController extends Controller
{
    private const string SESSION_KEY = 'accounts.bluesky.oauth';

    public function __construct(
        private readonly BlueskyOAuthConnector $connector,
        private readonly AccountConnectionService $connections,
        private readonly AccountConnectionIntent $intents,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        $validated = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255'],
            'pds_url' => ['nullable', 'url', 'max:255'],
        ]);

        $identifier = isset($validated['identifier'])
            ? ltrim(trim($validated['identifier']), '@')
            : null;

        try {
            $authorization = $this->connector->authorizationRedirect(
                $identifier !== '' ? $identifier : null,
                $this->clientId(),
                route('accounts.bluesky.oauth.callback'),
                $validated['pds_url'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()->route('accounts.index')->with('error', $exception->getMessage());
        }

        $request->session()->put(self::SESSION_KEY.'.'.$authorization['state'], [
            ...$authorization['context'],
            'connection_intent' => $this->intents->create($request, $authorization['state']),
        ]);

        return redirect()->away($authorization['url']);
    }

    private function clientId(): string
    {
        $callback = route('accounts.bluesky.oauth.callback');
        $host = parse_url($callback, PHP_URL_HOST);

        if (app()->isLocal() && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (! in_array($appHost, ['127.0.0.1', '::1', 'localhost'], true)) {
                return route('oauth.bluesky.metadata');
            }

            return 'http://localhost/?'.http_build_query([
                'redirect_uri' => $callback,
                'scope' => BlueskyOAuthConnector::SCOPE,
            ]);
        }

        return route('oauth.bluesky.metadata');
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        if ($request->filled('error')) {
            return redirect()->route('accounts.index')->with('error', 'Bluesky did not authorize the connection.');
        }

        $state = $request->query('state');
        if (! is_string($state) || preg_match('/\A[A-Za-z0-9]{64}\z/', $state) !== 1) {
            return redirect()->route('accounts.index')->with('error', 'Bluesky OAuth state expired. Please try again.');
        }
        $context = $request->session()->pull(self::SESSION_KEY.'.'.$state);

        if (! is_array($context) || ! $this->intents->isValid($request, $context['connection_intent'] ?? null)) {
            return redirect()->route('accounts.index')->with('error', 'Bluesky OAuth state expired. Please try again.');
        }

        try {
            $data = $this->connector->callback(
                (string) $request->query('code'),
                (string) $request->query('iss'),
                $context,
            );
        } catch (RuntimeException $exception) {
            Log::warning('Bluesky OAuth callback failed.', ['message' => $exception->getMessage()]);

            return redirect()->route('accounts.index')->with('error', $exception->getMessage());
        }

        $this->connections->store($data, $request->user());

        return redirect()->route('accounts.index')->with('success', 'Bluesky account connected.');
    }
}
