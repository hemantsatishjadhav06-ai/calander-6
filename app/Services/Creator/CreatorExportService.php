<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Models\CreatorExport;
use App\Models\CreatorExportBatch;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostReviewService;
use App\Support\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CreatorExportService
{
    /**
     * @param array<string, mixed> $data */
    public function attach(Post $post, array $data, string $actorId): CreatorExportBatch
    {
        $project = CreatorProject::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->findOrFail((string) $data['project_id']);
        $plan = $this->inspectFiles($data['slides']);
        $hash = CreatorDocument::hash([
            'project_id' => $project->id, 'project_revision' => (int) $data['project_revision'],
            'expected_post_revision' => $data['expected_post_revision'],
            'replace_media_ids' => $data['replace_media_ids'] ?? [],
            'target_segment_ref' => $data['target_segment_ref'] ?? '__head__',
            'slides' => array_map(static fn (array $item): array => [$item['slide_id'], $item['sha256'], $item['alt_text']], $plan),
        ]);
        if ($batch = $this->existingBatch($post, $data['idempotency_key'], $hash)) {
            return $batch;
        }
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');
        $this->assertCurrentProject($project, $data, $plan);
        $written = [];
        try {
            foreach ($plan as &$item) {
                $item['disk'] = FileStorage::diskName();
                $path = $item['file']->store('media/'.$post->workspace_id, $item['disk']);
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('An exported slide could not be stored.');
                }
                $item['path'] = $path;
                $written[] = [$item['disk'], $path];
            }
            unset($item);
            $created = false;
            $batch = DB::transaction(function () use ($post, $project, $data, $plan, $hash, $actorId, &$created): CreatorExportBatch {
                $project = CreatorProject::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->lockForUpdate()->findOrFail($project->id);
                $locked = Post::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->lockForUpdate()->findOrFail($post->id);
                if ($batch = $this->existingBatch($locked, $data['idempotency_key'], $hash)) {
                    return $batch;
                }
                abort_unless($locked->status->isEditable(), 422, 'This post can no longer be edited.');
                $this->assertCurrentProject($project, $data, $plan);
                $reviews = app(PostReviewService::class);
                abort_unless(hash_equals($reviews->revision($locked), $data['expected_post_revision']), 409, 'The post changed. Reload before attaching the design.');
                $replaceIds = $data['replace_media_ids'] ?? [];
                $oldMedia = PostMedia::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->orderBy('position')->orderBy('id')->get();
                $replacementExports = CreatorExport::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('project_id', $project->id)
                    ->whereIn('post_media_id', array_values($oldMedia->map(static fn (PostMedia $media): string => $media->id)->all()))->get();
                $validReplaceIds = $replacementExports->pluck('post_media_id')->all();
                abort_unless(array_diff($replaceIds, $validReplaceIds) === [], 422, 'Only this post\'s exports from this design can be replaced.');
                $segmentRef = $data['target_segment_ref'] ?? '__head__';
                $selectedExports = $replacementExports->whereIn('post_media_id', $replaceIds);
                abort_unless($selectedExports->pluck('slide_id')->unique()->count() === $selectedExports->count(), 422, 'Replace only one exported copy of each slide at a time.');
                $batch = CreatorExportBatch::create(['workspace_id' => $post->workspace_id, 'post_id' => $post->id,
                    'project_id' => $project->id, 'project_revision' => $project->revision, 'idempotency_key' => $data['idempotency_key'],
                    'request_hash' => $hash, 'document_snapshot' => $project->document, 'media_ids' => []]);
                $newIds = [];
                $newBySlide = [];
                foreach ($plan as $item) {
                    $media = PostMedia::create(['workspace_id' => $post->workspace_id, 'post_id' => $post->id,
                        'disk' => $item['disk'], 'path' => $item['path'], 'kind' => 'image', 'mime' => $item['mime'],
                        'width' => $item['width'], 'height' => $item['height'], 'size_bytes' => $item['size_bytes'],
                        'alt_text' => $item['alt_text'], 'position' => 0]);
                    $newIds[] = $media->id;
                    $newBySlide[$item['slide_id']] = $media->id;
                    CreatorExport::create(['workspace_id' => $post->workspace_id, 'project_id' => $project->id,
                        'project_revision' => $project->revision, 'batch_id' => $batch->id, 'post_media_id' => $media->id,
                        'slide_id' => $item['slide_id'], 'sha256' => $item['sha256']]);
                }
                $oldToNew = [];
                foreach ($selectedExports as $export) {
                    if (isset($newBySlide[$export->slide_id])) {
                        $oldToNew[$export->post_media_id] = $newBySlide[$export->slide_id];
                    }
                }
                $targetPlans = $this->targetPlans($locked, $replaceIds, $newIds, $oldToNew, $segmentRef, array_values($oldMedia->all()));
                $this->assertTargetCompatibility($locked, array_values($oldMedia->all()), $newIds, $targetPlans, $segmentRef);
                $orderedIds = $this->splice(array_values($oldMedia->map(static fn (PostMedia $media): string => $media->id)->all()), $replaceIds, $newIds);
                PostMedia::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->whereIn('id', $replaceIds)->update(['post_id' => null]);
                foreach ($orderedIds as $position => $id) {
                    PostMedia::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->whereKey($id)->update(['position' => $position]);
                }
                $this->updateTargets($locked, $targetPlans);
                $batch->update(['media_ids' => $newIds]);
                $locked->touch();
                $reviews->record($locked, 'creator_export_attached', 'creator', $actorId);
                $created = true;

                return $batch;
            });
            if (! $created) {
                $this->removeFiles($written);
            }

            return $batch;
        } catch (Throwable $exception) {
            $this->removeFiles($written);
            throw $exception;
        }
    }

    /**
     * @param list<array{slide_id: string, file: UploadedFile, alt_text?: string|null}> $slides
     * @return list<array<string, mixed>> */
    private function inspectFiles(array $slides): array
    {
        $plan = [];
        foreach ($slides as $slide) {
            $file = $slide['file'];
            $bytes = file_get_contents($file->getRealPath());
            if ($bytes === false) {
                throw new RuntimeException('An exported slide could not be read.');
            }
            $plan[] = ['slide_id' => $slide['slide_id'], 'file' => $file, 'alt_text' => $slide['alt_text'] ?? null,
                'sha256' => hash('sha256', $bytes), ...CreatorAssetStorage::inspect($bytes, (string) $file->getMimeType())];
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $plan */
    private function assertCurrentProject(CreatorProject $project, array $data, array $plan): void
    {
        abort_unless($project->revision === (int) $data['project_revision'], 409, 'The design changed. Export its latest revision.');
        $document = $project->document;
        abort_unless(array_column($plan, 'slide_id') === array_column($document['slides'], 'id'), 422, 'Export every slide once in its saved order.');
        foreach ($plan as $item) {
            abort_unless($item['width'] === $document['canvas']['width'] && $item['height'] === $document['canvas']['height'], 422, 'Exported slide dimensions must match the saved canvas.');
        }
    }

    private function existingBatch(Post $post, string $key, string $hash): ?CreatorExportBatch
    {
        $batch = CreatorExportBatch::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->where('idempotency_key', $key)->first();
        if ($batch !== null) {
            abort_unless(hash_equals($batch->request_hash, $hash), 409, 'This export key was already used for a different request.');
            $attached = PostMedia::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->where('post_id', $post->id)->whereIn('id', $batch->media_ids)->count();
            abort_unless($attached === count($batch->media_ids), 409, 'These exported slides are no longer attached. Start a new export.');
        }

        return $batch;
    }

    /**
     * @param list<string> $ids
     * @param list<string> $replace
     * @param list<string> $new
     * @return list<string> */
    private function splice(array $ids, array $replace, array $new): array
    {
        $result = [];
        $inserted = false;
        foreach ($ids as $id) {
            if (in_array($id, $replace, true)) {
                if (! $inserted) {
                    array_push($result, ...$new);
                    $inserted = true;
                }
            } else {
                $result[] = $id;
            }
        }
        if (! $inserted) {
            array_push($result, ...$new);
        }

        return $result;
    }

    /**
     * @param list<PostMedia> $media
     * @param list<string> $newIds
     * @param list<array<string, mixed>> $targetPlans */
    private function assertTargetCompatibility(Post $post, array $media, array $newIds, array $targetPlans, string $segmentRef): void
    {
        $targets = $post->targets()->get()->keyBy('connected_account_id');
        abort_if($segmentRef !== '__head__' && $targets->isEmpty(), 422, 'Save a destination and thread structure before exporting to this segment.');
        foreach ($targetPlans as $plan) {
            $target = $targets->get($plan['connected_account_id']);
            abort_unless($target instanceof PostTarget, 409, 'The destination changed. Reload before exporting.');
            $placements = $plan['placements'];
            abort_if($placements === [], 422, 'This destination excludes the design media. Update its media placement before exporting.');
            $destinations = array_unique(array_column(array_values(array_filter($placements,
                static fn (array $placement): bool => in_array($placement['media_id'], $newIds, true))), 'segment_ref'));
            foreach ($destinations as $destination) {
                abort_unless($destination === '__head__' || in_array($destination, $target->segment_breaks ?? [], true), 422, 'The target thread segment changed. Reload before exporting.');
                $segmentIds = array_column(array_values(array_filter($placements,
                    static fn (array $placement): bool => $placement['segment_ref'] === $destination)), 'media_id');
                foreach ($media as $item) {
                    if (! in_array($item->id, $segmentIds, true)) {
                        continue;
                    }
                    abort_if($item->isVideo(), 422, 'This thread segment already contains a video. Choose another segment for the design.');
                    abort_if($target->platform === Platform::Bluesky && $item->mime === 'image/gif', 422, 'Bluesky cannot combine this segment\'s GIF with design images.');
                }
            }
        }
        if ($targets->isEmpty()) {
            foreach ($media as $item) {
                abort_if($item->isVideo(), 422, 'This draft already contains a video. Choose another draft for the design.');
            }
        }
    }

    /**
     * @param list<string> $replaceIds
     * @param list<string> $newIds
     * @param array<string, string> $oldToNew
     * @param list<PostMedia> $oldMedia
     * @return list<array<string, mixed>> */
    private function targetPlans(Post $post, array $replaceIds, array $newIds, array $oldToNew, string $segmentRef, array $oldMedia): array
    {
        $targetPayloads = [];
        foreach ($post->targets()->with('placements')->get() as $target) {
            $placements = array_values($target->placements->sortBy('position')->map(static fn (PostMediaPlacement $placement): array => [
                'media_id' => $placement->post_media_id, 'segment_ref' => $placement->segment_ref, 'position' => $placement->position,
            ])->all());
            $override = $target->content_override;
            $overrideIds = is_array($override) ? ($override['media_ids'] ?? null) : null;
            if ($placements === []) {
                $baseIds = is_array($overrideIds) ? $overrideIds : array_map(static fn (PostMedia $item): string => $item->id, $oldMedia);
                $placements = array_map(static fn (string $id, int $position): array => ['media_id' => $id, 'segment_ref' => '__head__', 'position' => $position], $baseIds, array_keys($baseIds));
            }
            $presentIds = array_column($placements, 'media_id');
            $includedOld = array_values(array_intersect($presentIds, $replaceIds));
            $allowAddedSlides = ! is_array($overrideIds) && ($replaceIds === [] || count($includedOld) === count($replaceIds));
            $payload = ['connected_account_id' => $target->connected_account_id,
                'placements' => $this->remapPlacements($placements, $replaceIds, $newIds, $oldToNew, $segmentRef, $overrideIds, $allowAddedSlides)];
            if (is_array($overrideIds) && $replaceIds !== []) {
                $override['media_ids'] = $this->remapSelection($overrideIds, $replaceIds, $oldToNew);
                $payload['content_override'] = $override;
            }
            $targetPayloads[] = $payload;
        }

        return $targetPayloads;
    }

    /**
     * @param  list<array{media_id: string, segment_ref: string, position: int}>  $placements
     * @param  list<string>  $replaceIds
     * @param  list<string>  $newIds
     * @param  array<string, string>  $oldToNew
     * @param  list<string>|null  $overrideIds
     * @return list<array{media_id: string, segment_ref: string, position: int}>
     */
    private function remapPlacements(array $placements, array $replaceIds, array $newIds, array $oldToNew, string $segmentRef, ?array $overrideIds, bool $allowAddedSlides): array
    {
        $result = [];
        foreach ($placements as $placement) {
            $oldId = $placement['media_id'];
            if (! in_array($oldId, $replaceIds, true)) {
                $result[] = $placement;

                continue;
            }
            if (isset($oldToNew[$oldId]) && ($overrideIds === null || in_array($oldId, $overrideIds, true))) {
                $result[] = [...$placement, 'media_id' => $oldToNew[$oldId]];
            }
        }
        if ($allowAddedSlides) {
            $matchedIds = array_values($oldToNew);
            $nextPosition = 0;
            foreach ($result as $placement) {
                if ($placement['segment_ref'] === $segmentRef) {
                    $nextPosition = max($nextPosition, $placement['position'] + 1);
                }
            }
            foreach ($newIds as $id) {
                if (! in_array($id, $matchedIds, true)) {
                    $result[] = ['media_id' => $id, 'segment_ref' => $segmentRef, 'position' => $nextPosition++];
                }
            }
        }

        $rank = array_flip($newIds);
        $slotsBySegment = [];
        foreach ($result as $index => $placement) {
            if (isset($rank[$placement['media_id']])) {
                $slotsBySegment[$placement['segment_ref']][] = $index;
            }
        }
        foreach ($slotsBySegment as $slots) {
            usort($slots, static fn (int $left, int $right): int => ($result[$left]['position'] <=> $result[$right]['position']) ?: ($left <=> $right));
            $orderedIds = array_map(static fn (int $index): string => $result[$index]['media_id'], $slots);
            usort($orderedIds, static fn (string $left, string $right): int => $rank[$left] <=> $rank[$right]);
            foreach ($slots as $offset => $index) {
                $placement = $result[$index];
                $result[$index] = ['media_id' => $orderedIds[$offset], 'segment_ref' => $placement['segment_ref'], 'position' => $placement['position']];
            }
        }

        return $result;
    }

    /**
     * @param list<string> $ids
     * @param list<string> $replaceIds
     * @param array<string, string> $oldToNew
     * @return list<string> */
    private function remapSelection(array $ids, array $replaceIds, array $oldToNew): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (! in_array($id, $replaceIds, true)) {
                $result[] = $id;
            } elseif (isset($oldToNew[$id])) {
                $result[] = $oldToNew[$id];
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $targetPayloads */
    private function updateTargets(Post $post, array $targetPayloads): void
    {
        $data = DraftData::fromArray(['segments' => $post->segments, 'targets' => $targetPayloads]);
        $accountIds = array_column($targetPayloads, 'connected_account_id');
        $overrides = [];
        foreach ($targetPayloads as $payload) {
            if (array_key_exists('content_override', $payload)) {
                $overrides[$payload['connected_account_id']] = $payload['content_override'];
            }
        }
        app(DraftService::class)->syncTargets($post, $accountIds, $post->segments, [], $overrides, $post->mentions ?? [], [], $data);
    }

    /**
     * @param list<array{0: string, 1: string}> $files */
    private function removeFiles(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            FileStorage::disk($disk)->delete($path);
        }
    }
}
