<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\DeletePostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PostDeletionService
{
    public function delete(Post $post): bool
    {
        [$remote, $targets] = DB::transaction(function () use ($post): array {
            $targets = $post->targets()->lockForUpdate()->get();
            $post->refresh();
            $remote = in_array($post->status, [
                PostStatus::Publishing,
                PostStatus::Published,
                PostStatus::Partial,
                PostStatus::Failed,
                PostStatus::Deleted,
            ], true) || $targets->contains(fn (PostTarget $target): bool => $this->hasRemotePosts($target));

            if (! $remote) {
                $post->delete();

                return [false, new Collection];
            }

            $cleanup = $targets->filter(fn (PostTarget $target): bool => $this->hasRemotePosts($target))->values();

            foreach ($targets as $target) {
                $target->forceFill([
                    'status' => $this->hasRemotePosts($target) ? PostTargetStatus::Deleting->value : PostTargetStatus::Deleted->value,
                    'next_attempt_at' => null,
                ])->save();
            }

            $post->forceFill([
                'status' => PostStatus::Deleted->value,
                'deleted_at' => $post->deleted_at ?? now(),
            ])->save();

            return [true, $cleanup];
        });

        $targets->each(fn (PostTarget $target) => DeletePostTarget::dispatch($target));

        return $remote;
    }

    private function hasRemotePosts(PostTarget $target): bool
    {
        return $target->remote_id !== null || ($target->remote_ids ?? []) !== [];
    }
}
