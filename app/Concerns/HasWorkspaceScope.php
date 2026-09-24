<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * The tenant-isolation boundary for workspace-owned models.
 *
 * The scope is a no-op when no `workspace_id` context is bound. That is
 * deliberate: console commands and queued jobs work across workspaces (the
 * scheduler claims due posts for every tenant, and PublishPostTarget reads the
 * scoped `post`/`account` relations with no context), so constraining there
 * would stop publishing outright.
 *
 * It does mean an HTTP request that reaches a scoped query with no context
 * reads every tenant's rows. WorkspaceMiddleware closes that by always binding
 * a workspace for an authenticated request — falling back to
 * WorkspaceMiddleware::NO_WORKSPACE, which matches nothing — so do not rely on
 * this scope alone in a controller: scope explicitly where the workspace is
 * known.
 */
// @phpstan-ignore trait.unused (used by the workspace-owned models; the rule does not see trait use through the models' grouped `use` statements)
trait HasWorkspaceScope
{
    public static function bootHasWorkspaceScope(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            if ($workspaceId = Context::get('workspace_id')) {
                $builder->where($builder->getModel()->getTable().'.workspace_id', $workspaceId);
            }
        });

        static::creating(function (Model $model): void {
            if (! $model->getAttribute('workspace_id') && ($workspaceId = Context::get('workspace_id'))) {
                $model->setAttribute('workspace_id', $workspaceId);
            }
        });
    }
}
