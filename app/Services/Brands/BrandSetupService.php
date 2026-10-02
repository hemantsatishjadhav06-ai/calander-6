<?php

namespace App\Services\Brands;

use App\Enums\WorkspaceRole;
use App\Models\BlogDraft;
use App\Models\BrandProfile;
use App\Models\PostingSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Blogs\BlogDraftService;
use App\Services\Posts\DraftService;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrandSetupService
{
    public function setup(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $firstWorkspace = null;
            /** @var list<array{name: string, profile: array<string, string|null>, social_drafts: list<string>, blog: array<string, string>}> $templates */
            $templates = config('brands.templates', []);

            foreach ($templates as $template) {
                $workspace = Workspace::query()->where('owner_id', $owner->id)->where('name', $template['name'])->lockForUpdate()->first();

                if ($workspace === null) {
                    abort_unless(config('kit.workspaces.enabled') && app(InstanceSettings::class)->workspaceCreationEnabled(), 403, 'Workspace creation is disabled.');
                    $workspace = Workspace::create([
                        'name' => $template['name'],
                        'slug' => Str::slug($template['name']).'-'.Str::lower(Str::random(8)),
                        'owner_id' => $owner->id,
                        'timezone' => 'Asia/Kolkata',
                    ]);
                    WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => WorkspaceRole::Owner]);
                }

                abort_unless($owner->isOwnerOfWorkspace($workspace->id), 403);
                $workspace->forceFill(['requires_post_approval' => true])->save();
                $profile = BrandProfile::withoutGlobalScope('workspace')->firstOrCreate(['workspace_id' => $workspace->id], $template['profile']);
                PostingSchedule::withoutGlobalScope('workspace')->firstOrCreate(['workspace_id' => $workspace->id], ['timezone' => 'Asia/Kolkata']);
                if ($profile->wasRecentlyCreated) {
                    foreach ($template['social_drafts'] as $index => $text) {
                        $post = app(DraftService::class)->createDraft($workspace->id, $owner, ['kind' => 'none'], [$text]);
                        $post->forceFill(['planned_schedule_at' => now('Asia/Kolkata')->startOfDay()->addDays($index + 1)->setTime(10, 0)->utc()])->save();
                    }
                    $blog = BlogDraft::withoutGlobalScope('workspace')->firstOrCreate(
                        ['workspace_id' => $workspace->id, 'slug' => $template['blog']['slug']],
                        [...$template['blog'], 'author_id' => $owner->id],
                    );
                    if ($blog->wasRecentlyCreated) {
                        $blog->forceFill([
                            'review_requested_revision' => app(BlogDraftService::class)->revision($blog, ['website_url' => $profile->website_url, 'netlify_site_id' => $profile->netlify_site_id, 'repository_url' => $profile->repository_url]),
                            'review_requested_at' => now(),
                        ])->save();
                    }
                }
                $firstWorkspace ??= $workspace;
            }

            if ($firstWorkspace !== null) {
                $owner->forceFill(['current_workspace_id' => $firstWorkspace->id])->save();
                $user->setAttribute('current_workspace_id', $firstWorkspace->id);
                $user->syncOriginalAttribute('current_workspace_id');
                $user->unsetRelation('currentWorkspace');
            }
        });
    }
}
