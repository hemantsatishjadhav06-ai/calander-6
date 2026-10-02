<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceMiddleware
{
    /**
     * Stand-in workspace id for an authenticated user who has no current
     * workspace.
     *
     * HasWorkspaceScope adds no constraint at all when `workspace_id` is absent
     * from the context, so leaving it unset runs every workspace-scoped query
     * unconstrained — across every tenant on the instance. A user reaches that
     * state by leaving their last workspace (WorkspaceController::leave nulls
     * current_workspace_id), so it is self-service, not hypothetical.
     *
     * Binding a value that cannot match any row makes those queries fail closed
     * instead. It has to be a syntactically valid uuid: workspace_id is a uuid
     * column and Postgres rejects a non-uuid literal outright.
     *
     * The scope is deliberately left inert outside HTTP requests. Console
     * commands and queued jobs legitimately work across workspaces — the
     * scheduler claims due posts for every tenant, and PublishPostTarget reads
     * `$target->post()` and `$target->account()`, both scoped models, with no
     * context. Failing closed globally would stop all publishing.
     */
    public const NO_WORKSPACE = '00000000-0000-0000-0000-000000000000';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user instanceof User) {
            Context::add('workspace_id', $this->resolveWorkspaceId($user));
        }

        return $next($request);
    }

    /**
     * The workspace this request is bound to, repairing a missing or revoked
     * current_workspace_id before route-model binding and shared data resolve.
     */
    private function resolveWorkspaceId(User $user): string
    {
        if ($user->isMemberOfWorkspace($user->current_workspace_id)) {
            return (string) $user->current_workspace_id;
        }

        $fallback = $user->workspaceMemberships()->value('workspace_id');

        if ($user->current_workspace_id !== $fallback) {
            $user->forceFill(['current_workspace_id' => $fallback])->save();
        }
        $user->unsetRelation('currentWorkspace');

        if ($fallback) {

            return (string) $fallback;
        }

        return self::NO_WORKSPACE;
    }
}
