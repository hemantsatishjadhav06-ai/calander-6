<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Models\CreatorExport;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\PostMedia;

class CreatorExportFreshness
{
    /** @return list<array{media_id: string, project_id: string|null, rendered_revision: int, current_revision: int|null, slide_id: string, sha256: string}> */
    public function snapshot(Post $post): array
    {
        $mediaIds = PostMedia::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->select('id');
        $exports = CreatorExport::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->whereIn('post_media_id', $mediaIds)->orderBy('post_media_id')->get();
        $projects = CreatorProject::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->whereIn('id', $exports->pluck('project_id')->filter()->all())->get(['id', 'revision'])->keyBy('id');

        return $exports->map(fn (CreatorExport $export): array => [
            'media_id' => $export->post_media_id, 'project_id' => $export->project_id,
            'rendered_revision' => $export->project_revision, 'current_revision' => $projects->get($export->project_id)?->revision,
            'slide_id' => $export->slide_id, 'sha256' => $export->sha256,
        ])->values()->all();
    }

    public function hasStaleExports(Post $post): bool
    {
        foreach ($this->snapshot($post) as $export) {
            if ($export['rendered_revision'] !== $export['current_revision']) {
                return true;
            }
        }

        return false;
    }
}
