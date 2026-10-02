<?php

use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Enums\ConnectedAccountStatus;
use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\DeletePostTarget;
use App\Jobs\PublishPostTarget;
use App\Models\PostTargetAttempt;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

test('successful publish marks the target published with remote ids', function () {
    $target = publishTarget(['one', 'two']);
    bindConnector(PublishResult::success(['111', '222']));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('111')
        ->and($target->remote_ids)->toBe(['111', '222'])
        ->and($target->posted_at)->not->toBeNull();

    expect(PostTargetAttempt::where('post_target_id', $target->id)->where('status', 'published')->count())->toBe(1);
    expect($target->post->refresh()->status)->toBe(PostStatus::Published);
});

test('retryable failure schedules a retry and re-dispatches', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::RateLimited, 'slow', 429));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and($target->attempts)->toBe(1);

    Bus::assertDispatched(PublishPostTarget::class);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->where('status', 'retrying')->count())->toBe(1);
});

test('rate limited retry honors the provider retry-after delay', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::RateLimited, 'slow', 429, retryAfter: 900));

    Date::setTestNow(now()->startOfSecond());
    $expected = now()->addSeconds(900);

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->next_attempt_at->equalTo($expected))->toBeTrue();

    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->delay === 900);

    Date::setTestNow();
});

test('terminal failure marks the target failed without retry', function () {
    Bus::fake();
    $target = publishTarget();
    bindConnector(PublishResult::failure(ErrorKind::Validation, 'bad', 400));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::Validation);

    Bus::assertNotDispatched(PublishPostTarget::class);
    expect($target->post->refresh()->status)->toBe(PostStatus::Failed);
});

test('publish fails immediately when the account already needs attention', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->forceFill([
        'status' => ConnectedAccountStatus::NeedsAttention->value,
        'refresh_failed_at' => now(),
        'refresh_failure_reason' => 'X rejected the refresh token.',
    ])->save();

    bindConnector(fn () => throw new RuntimeException('connector should not be called'));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::AuthExpired)
        ->and($target->error_message)->toBe('X account needs attention. Reconnect it before publishing.')
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->error_kind)->toBe(ErrorKind::AuthExpired);

    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('auth expired result refreshes credentials once and retries the connector', function () {
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();

    config()->set('services.x.client_id', 'client-id');
    config()->set('services.x.client_secret', 'client-secret');
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 7200,
        ]),
    ]);

    $tokens = [];
    bindConnector(function (PublishContext $context) use (&$tokens): PublishResult {
        $tokens[] = $context->credentials['access_token'];

        return count($tokens) === 1
            ? PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401)
            : PublishResult::success(['111']);
    });

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($tokens)->toBe(['tok', 'fresh-token'])
        ->and($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->attempts)->toBe(1)
        ->and($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::Active);

    Http::assertSentCount(1);
});

test('auth expired after the recovery refresh marks the target failed without retrying', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([], 400),
    ]);
    bindConnector(PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_kind)->toBe(ErrorKind::AuthExpired)
        ->and($target->error_message)->toStartWith('Token refresh failed for account ')
        ->and($target->attempts)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull();
    expect($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::NeedsAttention);

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->attempt_no)->toBe(1)
        ->and($attempt->error_kind)->toBe(ErrorKind::AuthExpired);

    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('a transient refresh failure retries the publish without flipping the account', function () {
    Bus::fake();
    $target = publishTarget();
    $target->account()->firstOrFail()->secret()->firstOrFail()->forceFill([
        'refresh_token' => 'refresh-old',
    ])->save();
    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response([], 503),
    ]);
    bindConnector(PublishResult::failure(ErrorKind::AuthExpired, 'Unauthorized', 401));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->error_kind)->toBe(ErrorKind::ServerError)
        ->and($target->next_attempt_at)->not->toBeNull();
    expect($target->account()->firstOrFail()->status)->toBe(ConnectedAccountStatus::Active);

    Bus::assertDispatched(PublishPostTarget::class);
});

test('retry stops after five attempts', function () {
    Bus::fake();
    $target = publishTarget();
    $target->forceFill(['attempts' => 4])->save();
    bindConnector(PublishResult::failure(ErrorKind::ServerError, 'boom', 500));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('an uncaught exception closes the attempt and marks the target failed (never stuck publishing)', function () {
    $target = publishTarget();
    bindConnector(function (): never {
        throw new RuntimeException('boom');
    });

    $job = new PublishPostTarget($target);

    try {
        $job->handle(
            app(PublishConnectorRegistry::class),
            app(TokenManager::class),
            app(PostStatusRollup::class),
            app(BackoffSchedule::class),
        );
    } catch (RuntimeException) {
        // Laravel invokes failed() when the job throws.
        $job->failed(new RuntimeException('boom'));
    }

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_message)->not->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->finished_at)->not->toBeNull();

    expect($target->post->refresh()->status)->toBe(PostStatus::Failed);
});

test('failed() reconciles a fully-posted target to published (orphaned redelivery)', function () {
    // Simulates the SHOUTRRR-E scenario: a worker died after every segment was
    // posted; the DB queue redelivered the reserved message and tries=1 rejected it
    // as "attempted too many times" before handle() could record success.
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Publishing->value,
        'remote_ids' => ['111', '222'],
        'remote_id' => '111',
    ])->save();

    PostTargetAttempt::create([
        'post_target_id' => $target->id,
        'attempt_no' => 1,
        'status' => 'retrying',
        'started_at' => now(),
    ]);

    new PublishPostTarget($target->fresh())->failed(
        new MaxAttemptsExceededException('App\Jobs\PublishPostTarget has been attempted too many times.'),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('111')
        ->and($target->remote_ids)->toBe(['111', '222'])
        ->and($target->posted_at)->not->toBeNull()
        ->and($target->error_message)->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('published')
        ->and($attempt->finished_at)->not->toBeNull();

    expect($target->post->refresh()->status)->toBe(PostStatus::Published);
});

test('failed() resumes a partially-posted thread instead of failing it', function () {
    Bus::fake();
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Publishing->value,
        'attempts' => 1,
        'remote_ids' => ['111'],
        'remote_id' => '111',
    ])->save();

    PostTargetAttempt::create([
        'post_target_id' => $target->id,
        'attempt_no' => 1,
        'status' => 'retrying',
        'started_at' => now(),
    ]);

    new PublishPostTarget($target->fresh())->failed(new RuntimeException('worker killed mid-thread'));

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->next_attempt_at)->not->toBeNull();

    $attempt = PostTargetAttempt::where('post_target_id', $target->id)->latest('id')->first();
    expect($attempt->status)->toBe('retrying')
        ->and($attempt->finished_at)->not->toBeNull();

    Bus::assertDispatched(PublishPostTarget::class);
});

test('failed() gives up on a partial thread once the attempt budget is exhausted', function () {
    Bus::fake();
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Publishing->value,
        'attempts' => 5,
        'remote_ids' => ['111'],
        'remote_id' => '111',
    ])->save();

    new PublishPostTarget($target->fresh())->failed(new RuntimeException('still broken'));

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('failed() is a no-op when the target already reached a terminal state', function () {
    Bus::fake();
    // A redelivery can fire up to retry_after after the orphan was created, by which
    // point another path may have deleted the post. failed() must not resurrect it.
    $target = publishTarget(['one', 'two']);
    $target->forceFill([
        'status' => PostTargetStatus::Deleted->value,
        'remote_ids' => ['111', '222'],
        'remote_id' => '111',
    ])->save();

    new PublishPostTarget($target->fresh())->failed(
        new MaxAttemptsExceededException('App\Jobs\PublishPostTarget has been attempted too many times.'),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('failed() with no posted segments marks the target failed', function () {
    $target = publishTarget(['one']);
    $target->forceFill(['status' => PostTargetStatus::Publishing->value])->save();

    new PublishPostTarget($target->fresh())->failed(new RuntimeException('boom'));

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_message)->not->toBeNull();
});

test('job has tries=1 and a timeout below the queue retry_after', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900);

    // Invariant: the job timeout MUST stay below the queue connection's retry_after,
    // or a slow large-video run is released to a second worker mid-upload and double-posts.
    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

test('handle is a no-op on a terminal published target (stale retry / double dispatch)', function () {
    $target = publishTarget(status: 'published');
    $target->forceFill(['remote_id' => 'rid', 'remote_ids' => ['rid']])->save();

    bindConnector(function (): never {
        throw new RuntimeException('connector must not be called');
    });

    new PublishPostTarget($target->fresh())->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published)
        ->and($target->remote_id)->toBe('rid')
        ->and($target->attempts)->toBe(0);

    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
});

test('thread resumption passes already-posted ids to the connector', function () {
    $target = publishTarget(['one', 'two']);
    $target->forceFill(['remote_ids' => ['111']])->save();

    $seen = null;
    bindConnector(function (PublishContext $context) use (&$seen): PublishResult {
        $seen = $context->target->remote_ids;

        return PublishResult::success(['111', '222']);
    });

    new PublishPostTarget($target->fresh())->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($seen)->toBe(['111']);
});

test('overlapping jobs for the same target cannot publish concurrently', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);
    $duplicate = new PublishPostTarget($target);
    $calls = 0;
    bindConnector(function () use (&$calls): PublishResult {
        $calls++;

        return PublishResult::success(['111']);
    });

    $run = fn (PublishPostTarget $running) => $running->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );
    $middleware = $job->middleware()[0];

    $middleware->handle($job, function (PublishPostTarget $running) use ($duplicate, $run): void {
        $duplicate->middleware()[0]->handle($duplicate, $run);
        $run($running);
    });

    expect($calls)->toBe(1)
        ->and($target->refresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->attempts)->toBe(1);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(1);

    $lock = Cache::lock($middleware->getLockKey($job), 1);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('stale jobs respect a pending retry delay', function () {
    $target = publishTarget(status: 'publishing');
    $target->forceFill(['next_attempt_at' => now()->addMinutes(10)])->save();
    bindConnector(fn () => throw new RuntimeException('connector must not be called before the retry'));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->attempts)->toBe(0);
    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
});

test('stale jobs cannot resurrect a terminal failure', function () {
    $target = publishTarget(status: 'failed');
    bindConnector(fn () => throw new RuntimeException('connector must not be called after failure'));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->attempts)->toBe(0);
});

test('a deleted target is ignored if it disappears after the job is constructed', function () {
    $target = publishTarget();
    $job = new PublishPostTarget($target);
    $target->delete();
    bindConnector(fn () => throw new RuntimeException('connector must not publish a deleted target'));

    $job->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );
    $job->failed(new RuntimeException('a stale worker failed'));

    expect(PostTargetAttempt::where('post_target_id', $target->id)->count())->toBe(0);
});

test('deleting during a successful publish preserves deletion and queues cleanup for the late remote post', function () {
    $target = publishTarget();
    Bus::fake();
    Notification::fake();
    bindConnector(function (PublishContext $context): PublishResult {
        $context->target->newQuery()->whereKey($context->target->id)->update(['status' => PostTargetStatus::Deleted->value]);
        $context->target->post()->update(['status' => PostStatus::Deleted->value, 'deleted_at' => now()]);

        return PublishResult::success(['111']);
    });

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleting)
        ->and($target->remote_ids)->toBe(['111'])
        ->and($target->post->refresh()->status)->toBe(PostStatus::Deleted);
    Bus::assertDispatched(DeletePostTarget::class, fn (DeletePostTarget $job): bool => $job->target->id === $target->id && $job->target->remote_ids === ['111']);
    Notification::assertNothingSent();
});

test('deleting during a retryable publish failure does not resurrect the target or retry it', function () {
    $target = publishTarget();
    Bus::fake();
    Notification::fake();
    bindConnector(function (PublishContext $context): PublishResult {
        $context->target->newQuery()->whereKey($context->target->id)->update(['status' => PostTargetStatus::Deleted->value]);
        $context->target->post()->update(['status' => PostStatus::Deleted->value, 'deleted_at' => now()]);

        return PublishResult::failure(ErrorKind::ServerError, 'try later');
    });

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted)
        ->and($target->next_attempt_at)->toBeNull()
        ->and($target->post->refresh()->status)->toBe(PostStatus::Deleted);
    Bus::assertNotDispatched(PublishPostTarget::class);
    Notification::assertNothingSent();
});

test('a failed worker cleans up segments persisted after the author deleted the post', function () {
    $target = publishTarget(['one', 'two']);
    Bus::fake();
    Notification::fake();
    bindConnector(function (PublishContext $context): never {
        $context->target->post()->update(['status' => PostStatus::Deleted->value, 'deleted_at' => now()]);
        $context->target->newQuery()->whereKey($context->target->id)->update([
            'status' => PostTargetStatus::Deleted->value,
            'remote_ids' => json_encode(['111']),
        ]);

        throw new RuntimeException('worker died mid-thread');
    });
    $job = new PublishPostTarget($target);

    try {
        $job->handle(
            app(PublishConnectorRegistry::class),
            app(TokenManager::class),
            app(PostStatusRollup::class),
            app(BackoffSchedule::class),
        );
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleting)
        ->and($target->remote_ids)->toBe(['111'])
        ->and($target->post->refresh()->status)->toBe(PostStatus::Deleted);
    Bus::assertDispatched(DeletePostTarget::class);
    Bus::assertNotDispatched(PublishPostTarget::class);
    Notification::assertNothingSent();
});

test('a rollup from a stale post instance cannot resurrect a deleted post', function () {
    $target = publishTarget(status: 'published');
    $stale = $target->post;
    $stale->newQuery()->whereKey($stale->id)->update(['status' => PostStatus::Deleted->value, 'deleted_at' => now()]);

    app(PostStatusRollup::class)->recompute($stale);

    expect($stale->refresh()->status)->toBe(PostStatus::Deleted);
});

test('a target deleted before the publish claim never reaches the connector', function () {
    $target = publishTarget();
    $settings = Mockery::mock(InstanceSettings::class);
    $settings->shouldReceive('platformAvailable')->once()->andReturnUsing(function () use ($target): bool {
        $target->forceFill(['status' => PostTargetStatus::Deleted])->save();
        $target->post()->update(['status' => PostStatus::Deleted->value, 'deleted_at' => now()]);

        return true;
    });
    bindConnector(fn () => throw new RuntimeException('connector must not publish after deletion'));

    new PublishPostTarget($target)->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
        app(PostStatusRollup::class),
        app(BackoffSchedule::class),
        settings: $settings,
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted)
        ->and($target->attempts)->toBe(0);
});

test('an old worker failure cannot cancel an already scheduled retry', function () {
    $target = publishTarget(status: 'publishing');
    $target->forceFill(['next_attempt_at' => now()->addMinutes(5)])->save();
    Bus::fake();
    Notification::fake();

    new PublishPostTarget($target)->failed(new MaxAttemptsExceededException('old queue reservation was redelivered'));

    expect($target->refresh()->status)->toBe(PostTargetStatus::Publishing)
        ->and($target->next_attempt_at)->not->toBeNull();
    Bus::assertNotDispatched(PublishPostTarget::class);
    Notification::assertNothingSent();
});
