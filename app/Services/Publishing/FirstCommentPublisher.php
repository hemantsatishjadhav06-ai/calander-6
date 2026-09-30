<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Dto\Engagement\ReplyPostResult;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\InstagramEngagementConnector;
use App\Services\Engagement\EngagementConnectorRegistry;

class FirstCommentPublisher
{
    public function __construct(private readonly EngagementConnectorRegistry $registry) {}

    /** @param array<string, mixed> $credentials */
    public function send(ConnectedAccount $account, PostTarget $target, string $text, array $credentials): ReplyPostResult
    {
        if (! app(FirstCommentService::class)->capability($target)['supported'] || ! $target->remote_id) {
            return ReplyPostResult::unsupported('This published target does not support first comments.');
        }
        if ($target->platform === Platform::Instagram) {
            return app(InstagramEngagementConnector::class)->postFirstComment($account, $target, $text, $credentials);
        }

        // This is an in-memory root reference, never an inbox comment or an extra thread segment.
        $root = new PostTargetReply([
            'post_target_id' => $target->id,
            'platform' => $target->platform,
            'remote_reply_id' => $target->remote_id,
            'conversation_remote_id' => $target->remote_id,
        ]);
        $root->setRelation('target', $target);

        return $this->registry->for($target->platform)->postReply($account, $root, $text, $credentials);
    }
}
