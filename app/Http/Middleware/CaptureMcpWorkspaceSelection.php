<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\McpGrantWorkspace;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind each approved authorization code to its selected workspace so separate
 * consents for the same client cannot overwrite each other.
 */
class CaptureMcpWorkspaceSelection
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip on every route except the OAuth consent approval POST.
        // Using routeIs() means this guard is a no-op when no route is matched
        // (e.g. unit tests that invoke the middleware directly), and it short-
        // circuits all other matched routes — both cases are safe.
        if ($request->route() !== null && ! $request->routeIs('passport.authorizations.approve')) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();
        $workspaceId = $request->string('workspace_id')->toString();
        $clientId = $request->input('client_id') ?? $request->input('client');

        $serializedAuthorization = $request->hasSession() ? $request->session()->get('authRequest') : null;
        if (is_string($serializedAuthorization)) {
            $authorization = unserialize($serializedAuthorization, ['allowed_classes' => [
                AuthorizationRequest::class,
                Client::class,
                Scope::class,
                \Laravel\Passport\Bridge\User::class,
            ]]);

            if ($authorization instanceof AuthorizationRequestInterface) {
                $clientId = $authorization->getClient()->getIdentifier();
            }
        }

        $response = $next($request);

        $location = $response->headers->get('Location');
        $query = is_string($location) ? parse_url($location, PHP_URL_QUERY) : null;
        $parameters = [];
        if (is_string($query)) {
            parse_str($query, $parameters);
        }
        $code = $parameters['code'] ?? null;

        if ($response->isRedirection() && is_string($code) && $code !== ''
            && $user !== null && $workspaceId !== '' && $clientId !== null && $user->isMemberOfWorkspace($workspaceId)) {
            McpGrantWorkspace::create([
                'user_id' => $user->id,
                'client_id' => (string) $clientId,
                'access_token_id' => null,
                'workspace_id' => $workspaceId,
                'authorization_code_hash' => hash('sha256', $code),
            ]);
        }

        return $response;
    }
}
