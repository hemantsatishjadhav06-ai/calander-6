<?php

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Publishing\FirstCommentPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('sends Instagram first comments to published media comments not comment replies', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->published()->create(['platform' => Platform::Instagram, 'connected_account_id' => $account->id, 'remote_id' => 'ig-media-root', 'remote_ids' => ['ig-media-root']]);
    Http::fake(['https://graph.facebook.com/*/ig-media-root/comments' => Http::response(['id' => 'ig-comment'], 200)]);
    $result = app(FirstCommentPublisher::class)->send($account, $target, '#details', ['access_token' => 'fake-page-token']);
    expect($result->remoteReplyId)->toBe('ig-comment');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ig-media-root/comments') && $request['message'] === '#details' && $request['access_token'] === 'fake-page-token');
    Http::assertSentCount(1);
});

it('reuses Facebook root comments and X root replies without appending to thread tails', function (Platform $platform, string $url, array $response) {
    $account = ConnectedAccount::factory()->create(['platform' => $platform]);
    $target = PostTarget::factory()->published()->create(['platform' => $platform, 'connected_account_id' => $account->id, 'remote_id' => 'root', 'remote_ids' => ['root', 'thread-tail']]);
    Http::fake([$url => Http::response($response, 200)]);
    $result = app(FirstCommentPublisher::class)->send($account, $target, 'First comment', ['access_token' => 'fake-token']);
    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($request) => $platform === Platform::X ? $request['reply']['in_reply_to_tweet_id'] === 'root' : str_ends_with($request->url(), '/root/comments'));
    Http::assertSentCount(1);
})->with([
    [Platform::Facebook, 'https://graph.facebook.com/*/root/comments', ['id' => 'fb-comment']],
    [Platform::X, 'https://api.twitter.com/2/tweets', ['data' => ['id' => 'x-reply']]],
]);

it('writes Threads reply containers against the root post', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads, 'remote_account_id' => 'threads-user']);
    $target = PostTarget::factory()->published()->create(['platform' => Platform::Threads, 'remote_id' => 'root', 'remote_ids' => ['root', 'thread-tail']]);
    Http::fake([
        'https://graph.threads.net/v1.0/threads-user/threads' => Http::response(['id' => 'container']),
        'https://graph.threads.net/v1.0/container*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads-user/threads_publish' => Http::response(['id' => 'threads-reply']),
    ]);
    $result = app(FirstCommentPublisher::class)->send($account, $target, 'First comment', ['access_token' => 'fake-token']);
    expect($result->remoteReplyId)->toBe('threads-reply');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/threads-user/threads') && $request['reply_to_id'] === 'root');
    Http::assertSentCount(3);
});

it('does not create a comment against a story or a missing remote root', function (bool $story) {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
    $target = PostTarget::factory()->create(['platform' => Platform::Instagram, 'format' => $story ? PostFormat::Story : PostFormat::Feed, 'remote_id' => $story ? 'story-id' : null]);
    expect(app(FirstCommentPublisher::class)->send($account, $target, 'Comment', [])->isOk())->toBeFalse();
    Http::assertNothingSent();
})->with([true, false]);
