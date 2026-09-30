<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConnectedAccountStatus;
use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\FirstCommentDelivery;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\PostReviewService;
use App\Services\Publishing\FirstCommentPublisher;
use App\Services\Publishing\FirstCommentService;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendFirstComment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $deliveryId) {}

    public function handle(FirstCommentPublisher $publisher, TokenManager $tokens): void
    {
        $delivery = FirstCommentDelivery::with('target.account', 'target.post')->find($this->deliveryId);
        if ($delivery === null || ! in_array($delivery->status, ['pending', 'retryable'], true)
            || $delivery->attempts >= 3 || $delivery->next_attempt_at?->isFuture()) {
            return;
        }
        $target = $delivery->target;
        if ($target === null || $target->status !== PostTargetStatus::Published) {
            return;
        }
        $account = $target->account;
        $post = $target->post;
        if ($account === null || $post === null || $post->status === PostStatus::Deleted || $account->isDisabled()
            || $account->status === ConnectedAccountStatus::NeedsAttention
            || ! app(InstanceSettings::class)->platformAvailable($target->platform)) {
            $this->block($delivery, 'The post or connected account is no longer available.');

            return;
        }
        $workspace = $post->workspace()->firstOrFail();
        $subscriptions = app(WorkspaceSubscriptionGate::class);
        if (! $subscriptions->canPublish($workspace)
            || ($target->platform === Platform::X && ! $subscriptions->canPublishX($workspace))) {
            $this->block($delivery, 'Publishing access or the X publishing allowance is unavailable.');

            return;
        }
        try {
            $credentials = $tokens->fresh($account);
        } catch (Throwable) {
            $this->block($delivery, 'Could not authenticate. Reconnect the account before retrying.');

            return;
        }

        $claimed = DB::transaction(function () use ($delivery, $target): bool {
            $post = Post::withoutGlobalScopes()->lockForUpdate()->find($target->post_id);
            $freshTarget = PostTarget::query()->lockForUpdate()->find($target->id);
            $locked = FirstCommentDelivery::query()->lockForUpdate()->find($delivery->id);
            if ($post === null || $freshTarget === null || $locked === null
                || $post->status === PostStatus::Deleted || $freshTarget->status !== PostTargetStatus::Published
                || ! in_array($locked->status, ['pending', 'retryable'], true)
                || $locked->attempts >= 3 || $locked->next_attempt_at?->isFuture()) {
                return false;
            }
            $reviews = app(PostReviewService::class);
            if (! $reviews->canPublish($post) || ! hash_equals($locked->revision, $reviews->revision($post))) {
                $this->block($locked, 'The approved content revision changed. This first comment was not sent.');

                return false;
            }
            $freshAccount = $freshTarget->account()->withoutGlobalScopes()->first();
            if ($freshAccount === null || $freshAccount->isDisabled() || $freshAccount->status === ConnectedAccountStatus::NeedsAttention
                || ! app(InstanceSettings::class)->platformAvailable($freshTarget->platform)) {
                $this->block($locked, 'The connected account is no longer available.');

                return false;
            }
            if (! app(FirstCommentService::class)->capability($freshTarget)['supported']) {
                $this->block($locked, 'This target does not support first comments.');

                return false;
            }
            $locked->forceFill(['status' => 'sending', 'attempts' => $locked->attempts + 1, 'attempted_at' => now(), 'next_attempt_at' => null, 'error_message' => null])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }

        try {
            $result = $publisher->send($account, $target, $delivery->text, $credentials);
            $delivery->refresh();
            if ($result->isOk() && trim($result->remoteReplyId ?? '') !== '') {
                $delivery->forceFill(['status' => 'sent', 'remote_id' => $result->remoteReplyId, 'sent_at' => now(), 'error_message' => null])->save();

                return;
            }
            if (in_array($result->status, [EngagementStatus::RateLimited, EngagementStatus::AuthExpired], true)) {
                $delivery->forceFill([
                    'status' => 'retryable',
                    'error_message' => $result->status === EngagementStatus::RateLimited ? 'The platform rejected this attempt because of its rate limit.' : 'The platform rejected this attempt because authentication expired. Reconnect the account.',
                    'next_attempt_at' => $result->status === EngagementStatus::RateLimited && $delivery->attempts < 3 ? now()->addMinutes(5) : null,
                ])->save();

                return;
            }
            if ($result->status === EngagementStatus::Unsupported) {
                $this->block($delivery, 'The platform rejected the first comment. Check the account permissions and whether comments are allowed.', afterSend: true);

                return;
            }
            $this->uncertain();
        } catch (Throwable) {
            $this->uncertain();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->uncertain();
    }

    private function block(FirstCommentDelivery $delivery, string $message, bool $afterSend = false): void
    {
        FirstCommentDelivery::query()->whereKey($delivery->id)->whereIn('status', $afterSend ? ['sending'] : ['pending', 'retryable'])->update(['status' => 'blocked', 'error_message' => $message, 'next_attempt_at' => null]);
    }

    private function uncertain(): void
    {
        FirstCommentDelivery::query()->whereKey($this->deliveryId)->where('status', 'sending')->update([
            'status' => 'uncertain',
            'error_message' => 'Delivery could not be confirmed. Check the live post. Automatic retry is disabled to prevent duplicate comments.',
            'next_attempt_at' => null,
        ]);
    }
}
