<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Scope;
use Override;

class WorkspaceAuthorizationController extends AuthorizationController
{
    /**
     * Every connection must choose its workspace even if a previous token granted
     * the same OAuth scopes. Silent authorization cannot supply that selection.
     *
     * @param  array<int, Scope>  $scopes
     */
    #[Override]
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
