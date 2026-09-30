<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Jobs\SendFirstComment;
use App\Models\FirstCommentDelivery;
use App\Models\Post;
use App\Models\PostTarget;

class FirstCommentService
{
    public function enabled(Post $post, PostTarget $target): bool
    {
        return $target->first_comment_enabled ?? $post->first_comment_enabled;
    }

    public function text(Post $post, PostTarget $target): string
    {
        return trim($target->first_comment ?? $post->first_comment ?? '');
    }

    /** @return array{supported: bool, reason: string|null, max_length: int} */
    public function capability(PostTarget $target): array
    {
        $reason = match (true) {
            $target->format === PostFormat::Story => 'First comments are not supported for Stories.',
            $target->platform === Platform::LinkedIn => 'LinkedIn first comments are not available. Its current connector only replies to existing comments and requires approved Community Management permissions.',
            $target->platform === Platform::Bluesky => 'Bluesky first comments are not available because the published root content ID is not retained for a verified reply reference.',
            $target->platform === Platform::Discord => 'Discord webhook posts do not support first comments.',
            default => null,
        };

        return ['supported' => $reason === null, 'reason' => $reason, 'max_length' => match ($target->platform) {
            Platform::X => 280,
            Platform::Threads => 500,
            Platform::Instagram => 2200,
            default => 5000,
        }];
    }

    /** Capture the approved configuration in the same locked transaction as the publish context. */
    public function snapshot(Post $post, PostTarget $target, string $revision): void
    {
        $existing = $target->firstCommentDelivery()->first();
        if ($existing !== null && $existing->attempts > 0) {
            return;
        }
        if (! $this->enabled($post, $target)) {
            $existing?->forceFill(['status' => 'skipped', 'error_message' => 'First comment disabled before publishing.'])->save();

            return;
        }
        $capability = $this->capability($target);
        $text = $this->text($post, $target);
        $error = $capability['reason'];
        if ($error === null && ($text === '' || mb_strlen($text) > $capability['max_length'])) {
            $error = 'The first comment is empty or exceeds the supported length.';
        }
        FirstCommentDelivery::updateOrCreate(['post_target_id' => $target->id], [
            'revision' => $revision,
            'text' => $text,
            'status' => $error === null ? 'pending' : 'skipped',
            'error_message' => $error,
        ]);
    }

    public function dispatch(PostTarget $target): void
    {
        $delivery = $target->firstCommentDelivery()->whereIn('status', ['pending', 'retryable'])->first();
        if ($delivery !== null) {
            SendFirstComment::dispatch($delivery->id)->afterCommit();
        }
    }

    /** @return array<string, mixed> */
    public function view(Post $post, PostTarget $target): array
    {
        $delivery = $target->firstCommentDelivery;

        return [
            'enabled' => $this->enabled($post, $target),
            'text' => $delivery->text ?? $this->text($post, $target),
            ...$this->capability($target),
            'status' => $delivery->status ?? 'not_started',
            'attempts' => $delivery->attempts ?? 0,
            'error_message' => $delivery?->error_message,
            'remote_id' => $delivery?->remote_id,
            'retry_url' => route('posts.targets.first-comment.retry', ['post' => $post->id, 'target' => $target->id]),
            'sent_at' => $delivery?->sent_at?->toIso8601String(),
            'next_attempt_at' => $delivery?->next_attempt_at?->toIso8601String(),
        ];
    }
}
