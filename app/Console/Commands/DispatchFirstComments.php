<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostTargetStatus;
use App\Jobs\SendFirstComment;
use App\Models\FirstCommentDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DispatchFirstComments extends Command
{
    protected $signature = 'posts:dispatch-first-comments';

    protected $description = 'Recover unsent first comments and safely rejected rate-limited attempts';

    public function handle(): int
    {
        FirstCommentDelivery::query()->where('status', 'sending')->where('attempted_at', '<', now()->subMinutes(10))->update([
            'status' => 'uncertain',
            'error_message' => 'The delivery worker stopped before confirming the result. Check the live post. Retry is disabled to prevent duplicate comments.',
            'next_attempt_at' => null,
        ]);
        FirstCommentDelivery::query()
            ->where('attempts', '<', 3)
            ->where(fn (Builder $query) => $query->where('status', 'pending')->orWhere(fn (Builder $retry) => $retry->where('status', 'retryable')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())))
            ->whereHas('target', fn (Builder $query) => $query->where('status', PostTargetStatus::Published->value))
            ->eachById(fn (FirstCommentDelivery $delivery) => SendFirstComment::dispatch($delivery->id));

        return self::SUCCESS;
    }
}
