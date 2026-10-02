<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpGrantWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Description('Remove abandoned pending MCP workspace bindings and bindings whose access token no longer exists.')]
#[Signature('mcp:prune-bindings')]
class PruneMcpBindings extends Command
{
    public function handle(): int
    {
        $pending = McpGrantWorkspace::query()
            ->whereNull('access_token_id')
            ->where('created_at', '<', now()->subHour())
            ->delete();

        $orphaned = McpGrantWorkspace::query()
            ->whereNotNull('access_token_id')
            ->whereNotIn('access_token_id', DB::table('oauth_access_tokens')->select('id'))
            ->delete();

        $this->info("Pruned {$pending} pending and {$orphaned} orphaned bindings.");

        return self::SUCCESS;
    }
}
