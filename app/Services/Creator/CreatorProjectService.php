<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Enums\PostStatus;
use App\Models\CreatorExport;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatorProjectService
{
    /**
     * @param array<string, mixed> $data */
    public function create(User $actor, array $data): CreatorProject
    {
        $workspaceId = (string) $actor->current_workspace_id;
        $document = CreatorDocument::validate($data['document'], $workspaceId);

        return CreatorProject::create(['workspace_id' => $workspaceId, 'created_by_id' => $actor->id,
            'name' => $data['name'], 'revision' => 1, 'document' => $document, 'document_hash' => CreatorDocument::hash($document)]);
    }

    /**
     * @param array<string, mixed> $data */
    public function update(CreatorProject $project, array $data): CreatorProject
    {
        $document = CreatorDocument::validate($data['document'], $project->workspace_id);

        return DB::transaction(function () use ($project, $data, $document): CreatorProject {
            $locked = CreatorProject::withoutGlobalScopes()->where('workspace_id', $project->workspace_id)->lockForUpdate()->findOrFail($project->id);
            abort_unless($locked->revision === (int) $data['expected_revision'], 409, 'The design changed. Reload before saving.');
            $hash = CreatorDocument::hash($document);
            if (! hash_equals($locked->document_hash, $hash)) {
                $mediaIds = CreatorExport::withoutGlobalScopes()->where('workspace_id', $project->workspace_id)->where('project_id', $project->id)->select('post_media_id');
                $postIds = PostMedia::withoutGlobalScopes()->where('workspace_id', $project->workspace_id)->whereIn('id', $mediaIds)->whereNotNull('post_id')->select('post_id');
                $publishing = Post::withoutGlobalScopes()->where('workspace_id', $project->workspace_id)->whereIn('id', $postIds)->where('status', PostStatus::Publishing->value)->exists();
                abort_if($publishing, 409, 'This design is being published. Wait for publishing to finish before editing it.');
                $locked->revision++;
            }
            $locked->fill(['name' => $data['name'], 'document' => $document, 'document_hash' => $hash])->save();

            return $locked;
        });
    }
}
