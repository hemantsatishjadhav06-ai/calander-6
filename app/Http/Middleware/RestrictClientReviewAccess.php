<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Reviews\ClientReviewAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictClientReviewAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(ClientReviewAccess::class)->isClient($request->user())) {
            return $next($request);
        }

        if ($request->routeIs('home', 'dashboard')) {
            return redirect()->route('reviews.index');
        }

        abort_unless($request->routeIs('reviews.*', 'logout', 'workspaces.switch', 'verification.*', 'share.show', 'storage.*'), 403, 'Client reviewers can access only their assigned review queue.');

        return $next($request);
    }
}
