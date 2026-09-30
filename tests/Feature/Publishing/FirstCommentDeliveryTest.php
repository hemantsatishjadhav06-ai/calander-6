<?php

use App\Dto\Engagement\ReplyPostResult;
use App\Dto\Post\DraftData;
use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Jobs\SendFirstComment;
use App\Models\ConnectedAccount;
use App\Models\FirstCommentDelivery;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostDuplicator;
use App\Services\Posts\PostReviewService;
use App\Services\Posts\PublishPrecheck;
use App\Services\Publishing\BackoffSchedule;
use App\Services\Publishing\FirstCommentPublisher;
use App\Services\Publishing\FirstCommentService;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    Bus::fake();
    config()->set('subscriptions.enabled', false);
    [$this->actor, $this->workspace] = ownerActingIn();
});

function firstCommentTarget(bool $enabled = true, Platform $platform = Platform::Instagram): PostTarget
{
    $user = auth()->user();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id, 'author_id' => $user->id, 'first_comment_enabled' => $enabled, 'first_comment' => 'More details here.']);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id, 'platform' => $platform]);

    return PostTarget::factory()->for($post)->create(['connected_account_id' => $account->id, 'platform' => $platform]);
}

function pendingFirstComment(PostTarget $target): FirstCommentDelivery
{
    $post = $target->post;
    app(FirstCommentService::class)->snapshot($post, $target, app(PostReviewService::class)->revision($post));
    $target->forceFill(['status' => PostTargetStatus::Published, 'remote_id' => 'published-root', 'remote_ids' => ['published-root', 'thread-part-two']])->save();

    return $target->firstCommentDelivery()->firstOrFail();
}

function runFirstComment(FirstCommentDelivery $delivery, ReplyPostResult $result): void
{
    $publisher = Mockery::mock(FirstCommentPublisher::class);
    $publisher->shouldReceive('send')->once()->andReturn($result);
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->andReturn(['access_token' => 'test-token']);
    (new SendFirstComment($delivery->id))->handle($publisher, $tokens);
}

it('defaults delivery off and never turns a stored suggestion into a send', function () {
    $target = firstCommentTarget(false);
    app(FirstCommentService::class)->snapshot($target->post, $target, app(PostReviewService::class)->revision($target->post));
    app(FirstCommentService::class)->dispatch($target);
    expect(FirstCommentDelivery::count())->toBe(0);
    Bus::assertNotDispatched(SendFirstComment::class);
});

it('persists post and target settings and preserves them through partial edits', function () {
    $target = firstCommentTarget();
    $data = DraftData::fromArray(['segments' => ['Updated'], 'destination' => ['kind' => 'account', 'id' => $target->connected_account_id], 'first_comment' => 'Post default', 'targets' => [['connected_account_id' => $target->connected_account_id, 'first_comment_enabled' => false, 'first_comment' => 'Account text']]]);
    $post = app(DraftService::class)->updateDraft($target->post, $data);
    $post = app(DraftService::class)->updateDraft($post, DraftData::fromArray(['segments' => ['Again']]), preserveDestination: true);
    expect($post->first_comment_enabled)->toBeTrue()->and($post->first_comment)->toBe('Post default')
        ->and($post->targets->first()->first_comment_enabled)->toBeFalse()->and($post->targets->first()->first_comment)->toBe('Account text');
});

it('allows explicit null target inheritance and keeps comments separate from sections', function () {
    $target = firstCommentTarget();
    $target->forceFill(['first_comment_enabled' => true, 'first_comment' => 'Override'])->save();
    $post = app(DraftService::class)->updateDraft($target->post, DraftData::fromArray(['segments' => ['A', 'B'], 'targets' => [['connected_account_id' => $target->connected_account_id, 'first_comment_enabled' => null, 'first_comment' => null]]]), preserveDestination: true);
    $target->refresh();
    expect($target->first_comment_enabled)->toBeNull()->and($target->first_comment)->toBeNull()
        ->and(app(FirstCommentService::class)->text($post, $target))->toBe('More details here.')
        ->and($target->sections)->not->toContain('More details here.');
});

it('invalidates approvals when first-comment content or opt-in changes', function (string $scope, string $field, mixed $value) {
    $target = firstCommentTarget();
    $post = $target->post;
    $reviews = app(PostReviewService::class);
    $revision = $reviews->revision($post);
    $reviews->act($post, $this->actor, 'submit', $revision, null, 'dashboard');
    $reviews->act($post, $this->actor, 'approve', $revision, null, 'dashboard');
    ($scope === 'post' ? $post : $target)->forceFill([$field => $value])->save();
    expect($reviews->canPublish($post->fresh()))->toBeFalse();
})->with([
    ['post', 'first_comment', 'Changed'], ['post', 'first_comment_enabled', false],
    ['target', 'first_comment', 'Changed'], ['target', 'first_comment_enabled', false],
]);

it('snapshots and dispatches only after a successful publish', function (bool $success) {
    $target = firstCommentTarget(true, Platform::X);
    bindConnector($success ? PublishResult::success(['root', 'second']) : PublishResult::failure(ErrorKind::Validation, 'Rejected'));
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->andReturn(['access_token' => 'test-token']);
    (new PublishPostTarget($target))->handle(app(PublishConnectorRegistry::class), $tokens, app(PostStatusRollup::class), app(BackoffSchedule::class));
    expect($target->firstCommentDelivery()->first()->text)->toBe('More details here.');
    if ($success) {
        Bus::assertDispatched(SendFirstComment::class);
    } else {
        Bus::assertNotDispatched(SendFirstComment::class);
    }
})->with([true, false]);

it('does not duplicate a sent comment or change the published thread ids', function () {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    runFirstComment($delivery, ReplyPostResult::ok('comment-one'));
    $publisher = Mockery::mock(FirstCommentPublisher::class);
    $publisher->shouldNotReceive('send');
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    (new SendFirstComment($delivery->id))->handle($publisher, $tokens);
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->remote_id)->toBe('comment-one')
        ->and($target->fresh()->remote_ids)->toBe(['published-root', 'thread-part-two']);
});

it('does not reenter an in-flight claim', function () {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->andReturn(['access_token' => 'test-token']);
    $publisher = Mockery::mock(FirstCommentPublisher::class);
    $publisher->shouldReceive('send')->once()->andReturnUsing(function () use ($delivery, $tokens, &$publisher) {
        (new SendFirstComment($delivery->id))->handle($publisher, $tokens);

        return ReplyPostResult::ok('comment-once');
    });
    (new SendFirstComment($delivery->id))->handle($publisher, $tokens);
    expect($delivery->fresh()->attempts)->toBe(1);
});

it('never retries uncertain outcomes or a success without a remote id', function (ReplyPostResult $result) {
    $delivery = pendingFirstComment(firstCommentTarget());
    runFirstComment($delivery, $result);
    expect($delivery->fresh()->status)->toBe('uncertain');
    $this->artisan('posts:dispatch-first-comments')->assertSuccessful();
    Bus::assertNotDispatched(SendFirstComment::class);
    $this->postJson(route('posts.targets.first-comment.retry', ['post' => $delivery->target->post_id, 'target' => $delivery->post_target_id]))->assertUnprocessable();
})->with([fn () => ReplyPostResult::failed('timed out'), fn () => ReplyPostResult::ok('')]);

it('safely retries an explicit rate-limit rejection without republishing the post', function () {
    $delivery = pendingFirstComment(firstCommentTarget());
    runFirstComment($delivery, ReplyPostResult::rateLimited());
    expect($delivery->fresh()->status)->toBe('retryable')->and($delivery->fresh()->next_attempt_at)->not->toBeNull();
    $this->travel(6)->minutes();
    $this->artisan('posts:dispatch-first-comments')->assertSuccessful();
    Bus::assertDispatched(SendFirstComment::class);
    Bus::assertNotDispatched(PublishPostTarget::class);
    runFirstComment($delivery->fresh(), ReplyPostResult::ok('comment-after-rate-limit'));
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(2);
});

it('bounds safely rejected attempts and requires manual auth retries', function () {
    $delivery = pendingFirstComment(firstCommentTarget());
    runFirstComment($delivery, ReplyPostResult::authExpired());
    expect($delivery->fresh()->status)->toBe('retryable')->and($delivery->fresh()->next_attempt_at)->toBeNull();
    $this->artisan('posts:dispatch-first-comments')->assertSuccessful();
    Bus::assertNotDispatched(SendFirstComment::class);
    $delivery->forceFill(['attempts' => 3])->save();
    $this->postJson(route('posts.targets.first-comment.retry', ['post' => $delivery->target->post_id, 'target' => $delivery->post_target_id]))->assertUnprocessable();
});

it('blocks an unsent comment when its published content revision changed', function () {
    $delivery = pendingFirstComment(firstCommentTarget());
    $delivery->target->post->forceFill(['first_comment' => 'Unapproved edit'])->save();
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldReceive('fresh')->once()->andReturn(['access_token' => 'test-token']);
    $publisher = Mockery::mock(FirstCommentPublisher::class);
    $publisher->shouldNotReceive('send');
    (new SendFirstComment($delivery->id))->handle($publisher, $tokens);
    expect($delivery->fresh()->status)->toBe('blocked')->and($delivery->fresh()->attempts)->toBe(0);
});

it('recovers missing dispatches but quarantines a crashed in-flight write', function () {
    $pending = pendingFirstComment(firstCommentTarget());
    $stuck = pendingFirstComment(firstCommentTarget());
    $stuck->forceFill(['status' => 'sending', 'attempted_at' => now()->subMinutes(11), 'attempts' => 1])->save();
    $this->artisan('posts:dispatch-first-comments')->assertSuccessful();
    Bus::assertDispatched(SendFirstComment::class, fn (SendFirstComment $job) => $job->deliveryId === $pending->id);
    Bus::assertNotDispatched(SendFirstComment::class, fn (SendFirstComment $job) => $job->deliveryId === $stuck->id);
    expect($stuck->fresh()->status)->toBe('uncertain');
});

it('does not resurrect sent delivery when a queue worker reports failure', function () {
    $delivery = pendingFirstComment(firstCommentTarget());
    runFirstComment($delivery, ReplyPostResult::ok('sent-comment'));
    (new SendFirstComment($delivery->id))->failed(new RuntimeException('Late queue failure'));
    expect($delivery->fresh()->status)->toBe('sent');
});

it('records unsupported platforms and stories honestly without making requests', function (Platform $platform, PostFormat $format) {
    $target = firstCommentTarget(true, $platform);
    $target->forceFill(['format' => $format])->save();
    $delivery = pendingFirstComment($target);
    expect($delivery->status)->toBe('skipped')->and($delivery->error_message)->not->toBeEmpty();
    app(FirstCommentService::class)->dispatch($target);
    Bus::assertNotDispatched(SendFirstComment::class);
    Http::assertNothingSent();
})->with([
    [Platform::LinkedIn, PostFormat::Feed], [Platform::Bluesky, PostFormat::Feed], [Platform::Discord, PostFormat::Feed],
    [Platform::Instagram, PostFormat::Story], [Platform::Facebook, PostFormat::Story],
]);

it('rejects empty and over-limit enabled first comments before publishing', function (string $text) {
    $target = firstCommentTarget(true, Platform::X);
    $target->post->forceFill(['first_comment' => $text])->save();
    $issues = app(PublishPrecheck::class)->blockingTargets($target->post->fresh(['targets.account', 'media']));
    expect($issues[0]['issues'])->toContain('first_comment_invalid');
})->with(['', str_repeat('x', 281)]);

it('copies first-comment configuration but never delivery history', function () {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    $delivery->forceFill(['status' => 'sent', 'remote_id' => 'old-comment', 'attempts' => 1])->save();
    $copy = app(PostDuplicator::class)->duplicate($target->post);
    expect($copy->first_comment_enabled)->toBeTrue()->and($copy->first_comment)->toBe('More details here.')
        ->and($copy->targets->first()->firstCommentDelivery()->exists())->toBeFalse();
});

it('isolates retries to the current workspace and target post', function () {
    $delivery = pendingFirstComment(firstCommentTarget());
    $delivery->forceFill(['status' => 'retryable'])->save();
    $otherPost = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->postJson(route('posts.targets.first-comment.retry', ['post' => $otherPost->id, 'target' => $delivery->post_target_id]))->assertNotFound();
    $foreign = PostTarget::factory()->published()->create();
    $this->postJson(route('posts.targets.first-comment.retry', ['post' => $foreign->post_id, 'target' => $foreign->id]))->assertNotFound();
});

it('does not send before publication or after deletion', function (string $status) {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    $target->forceFill(['status' => $status])->save();
    $publisher = Mockery::mock(FirstCommentPublisher::class);
    $publisher->shouldNotReceive('send');
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    (new SendFirstComment($delivery->id))->handle($publisher, $tokens);
    expect($delivery->fresh()->attempts)->toBe(0);
})->with(['pending', 'failed', 'publishing', 'deleting', 'deleted']);

it('blocks a disabled account before authenticating or sending', function () {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    $target->account->forceFill(['disabled_at' => now()])->save();
    $publisher = Mockery::mock(FirstCommentPublisher::class);
    $publisher->shouldNotReceive('send');
    $tokens = Mockery::mock(TokenManager::class);
    $tokens->shouldNotReceive('fresh');
    (new SendFirstComment($delivery->id))->handle($publisher, $tokens);
    expect($delivery->fresh()->status)->toBe('blocked');
});

it('lets an authorized user retry a safely blocked comment separately', function () {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    $delivery->forceFill(['status' => 'blocked', 'error_message' => 'Reconnect'])->save();
    $this->postJson(route('posts.targets.first-comment.retry', ['post' => $target->post_id, 'target' => $target->id]))->assertOk();
    expect($delivery->fresh()->status)->toBe('pending');
    Bus::assertDispatched(SendFirstComment::class);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

it('returns the sent snapshot in delivery status even if editable copy later diverges', function () {
    $target = firstCommentTarget();
    $delivery = pendingFirstComment($target);
    $delivery->forceFill(['status' => 'sent', 'remote_id' => 'comment-1'])->save();
    $target->post->forceFill(['first_comment' => 'Later text'])->save();
    $view = app(FirstCommentService::class)->view($target->post->fresh(), $target->fresh());
    expect($view['text'])->toBe('More details here.');
});
