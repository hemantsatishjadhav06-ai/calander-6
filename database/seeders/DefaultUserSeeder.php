<?php

namespace Database\Seeders;

use App\Enums\InstanceRole;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Seeder;

class DefaultUserSeeder extends Seeder
{
    /**
     * Seed the default development user and workspace.
     *
     * Refuses to run outside local/testing. This seeder creates a known
     * instance-OWNER login (test@example.com / "password", pre-verified), so
     * running it on a deployed instance publishes an admin backdoor. It is
     * called directly (composer dev, docker-compose.dev, and some deploy
     * pre-commands) which bypasses DatabaseSeeder's isLocal() guard, so the
     * guard has to live here. Set ALLOW_DEFAULT_USER_SEED=true to override
     * deliberately (e.g. seeding a throwaway demo instance).
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing') && ! filter_var(env('ALLOW_DEFAULT_USER_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn(
                'DefaultUserSeeder skipped: it creates a known-password instance owner and must not run outside local/testing. '
                .'Set ALLOW_DEFAULT_USER_SEED=true to override.'
            );

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
                'instance_role' => InstanceRole::Owner,
            ],
        );

        if (! $user->isInstanceOwner()) {
            $user->forceFill(['instance_role' => InstanceRole::Owner])->save();
        }

        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'test-workspace'],
            [
                'name' => 'Test Workspace',
                'owner_id' => $user->id,
                'timezone' => 'UTC',
            ],
        );

        WorkspaceMembership::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            ['role' => WorkspaceRole::Owner],
        );

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $secondUser = User::query()->firstOrCreate(
            ['email' => 'test2@example.com'],
            [
                'name' => 'Test User 2',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $secondUser->id)
            ->delete();

        $secondWorkspace = Workspace::query()->firstOrCreate(
            ['slug' => 'test-workspace-2'],
            [
                'name' => 'Test Workspace 2',
                'owner_id' => $secondUser->id,
                'timezone' => 'UTC',
            ],
        );

        WorkspaceMembership::query()->firstOrCreate(
            [
                'workspace_id' => $secondWorkspace->id,
                'user_id' => $secondUser->id,
            ],
            ['role' => WorkspaceRole::Owner],
        );

        $secondUser->forceFill(['current_workspace_id' => $secondWorkspace->id])->save();
    }
}
