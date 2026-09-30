<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceBrandProfile;
use App\Services\Creator\CreatorDocument;
use App\Services\Creator\CreatorProjectService;
use Illuminate\Support\Facades\DB;

class ContentWorkflowService
{
    /** @param array<string, mixed> $data */
    public function saveBrand(User $actor, array $data): WorkspaceBrandProfile
    {
        $workspaceId = (string) $actor->current_workspace_id;

        return DB::transaction(function () use ($workspaceId, $data): WorkspaceBrandProfile {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $brand = WorkspaceBrandProfile::query()->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            abort_unless(($brand->revision ?? 0) === (int) $data['expected_revision'], 409, 'The brand profile changed. Reload before saving.');
            unset($data['expected_revision'], $data['expected_workspace_id']);
            $brand ??= new WorkspaceBrandProfile(['workspace_id' => $workspaceId, 'revision' => 0]);
            $brand->fill($data);
            $brand->revision++;
            $brand->save();

            return $brand;
        });
    }

    /** @param array<string, mixed> $data */
    public function saveTemplate(User $actor, array $data, ?ContentTemplate $template = null): ContentTemplate
    {
        $workspaceId = (string) $actor->current_workspace_id;
        abort_if($template !== null && $template->workspace_id !== $workspaceId, 404);

        return DB::transaction(function () use ($actor, $workspaceId, $data, $template): ContentTemplate {
            $locked = $template ? ContentTemplate::query()->where('workspace_id', $workspaceId)->lockForUpdate()->findOrFail($template->id) : null;
            abort_if($locked && $locked->revision !== (int) $data['expected_revision'], 409, 'The template changed. Reload before saving.');
            $document = $locked?->document;
            if (array_key_exists('source_project_id', $data)) {
                $document = null;
                if ($data['source_project_id']) {
                    $project = CreatorProject::query()->where('workspace_id', $workspaceId)->lockForUpdate()->findOrFail((string) $data['source_project_id']);
                    abort_unless($project->revision === (int) $data['source_project_revision'], 409, 'The design changed. Save and select the current revision.');
                    $document = CreatorDocument::validate($project->document, $workspaceId);
                } else {
                    $data['source_project_revision'] = null;
                }
            } else {
                unset($data['source_project_revision']);
            }
            $archived = array_key_exists('archived', $data) ? ($data['archived'] ? now() : null) : $locked?->archived_at;
            unset($data['expected_revision'], $data['expected_workspace_id'], $data['archived']);
            $data['brief'] ??= '';
            $locked ??= new ContentTemplate(['workspace_id' => $workspaceId, 'created_by_id' => $actor->id, 'revision' => 0]);
            $locked->fill([...$data, 'document' => $document, 'archived_at' => $archived]);
            $locked->revision++;
            $locked->save();

            return $locked;
        });
    }

    public function instantiate(User $actor, ContentTemplate $template, int $expectedRevision): CreatorProject
    {
        abort_unless($template->workspace_id === $actor->current_workspace_id, 404);

        return DB::transaction(function () use ($actor, $template, $expectedRevision): CreatorProject {
            $locked = ContentTemplate::query()->where('workspace_id', $actor->current_workspace_id)->lockForUpdate()->findOrFail($template->id);
            abort_unless($locked->revision === $expectedRevision, 409, 'The template changed. Reload before using it.');
            abort_if($locked->archived_at !== null || $locked->document === null, 422, 'Choose an active template with a saved design.');

            return app(CreatorProjectService::class)->create($actor, ['name' => $locked->name, 'document' => $locked->document]);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveIdea(User $actor, array $data, ?ContentIdea $idea = null): ContentIdea
    {
        $workspaceId = (string) $actor->current_workspace_id;
        abort_if($idea !== null && $idea->workspace_id !== $workspaceId, 404);

        return DB::transaction(function () use ($actor, $workspaceId, $data, $idea): ContentIdea {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $locked = $idea ? ContentIdea::query()->where('workspace_id', $workspaceId)->lockForUpdate()->findOrFail($idea->id) : null;
            abort_if($locked && $locked->revision !== (int) $data['expected_revision'], 409, 'The idea changed. Reload before saving.');
            abort_if($locked?->draft_post_id !== null, 422, 'This idea already has a draft. Edit the draft in the composer.');
            unset($data['expected_revision'], $data['expected_workspace_id']);
            $locked ??= new ContentIdea(['workspace_id' => $workspaceId, 'created_by_id' => $actor->id, 'revision' => 0]);
            if (! $locked->exists || $locked->status !== $data['status']) {
                $data['position'] = (int) ContentIdea::query()->where('workspace_id', $workspaceId)->where('status', $data['status'])->max('position') + 1;
            }
            $locked->fill($data);
            $locked->revision++;
            $locked->save();

            return $locked;
        });
    }

    public function convert(User $actor, ContentIdea $idea, int $expectedRevision): Post
    {
        return app(ContentTemplateDraftService::class)->convert($actor, $idea, $expectedRevision);
    }
}
