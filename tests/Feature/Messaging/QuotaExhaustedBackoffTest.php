<?php

declare(strict_types=1);

use App\Dto\Messaging\ConversationFetchResult;
use App\Enums\Platform;
use App\Jobs\FetchAccountMessages;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Services\Messaging\Contracts\DirectMessageConnector;
use App\Services\Messaging\MessageConnectorRegistry;
use App\Services\Messaging\MessagePersister;
use App\Services\Publishing\TokenManager;
use App\Support\InstanceSettings;

beforeEach(fn () => config()->set('messages.enabled', true));

function quotaExhaustedAccount(): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        // Future expiry keeps TokenManager::fresh() off the network.
        'token_expires_at' => now()->addDay(),
    ]);

    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id]);

    return $account;
}

function runMessagesJobWith(ConnectedAccount $account, ConversationFetchResult $result): void
{
    $connector = Mockery::mock(DirectMessageConnector::class);
    $connector->shouldReceive('fetchConversations')->andReturn($result);
    $registry = Mockery::mock(MessageConnectorRegistry::class);
    $registry->shouldReceive('for')->andReturn($connector);

    (new FetchAccountMessages($account))->handle(
        $registry,
        app(TokenManager::class),
        app(MessagePersister::class),
        app(InstanceSettings::class),
    );
}

test('a quota-exhausted dm fetch parks the account for the configured backoff', function () {
    $this->freezeTime();
    config()->set('messages.quota_exhausted_backoff', 21600);

    $account = quotaExhaustedAccount();

    runMessagesJobWith($account, ConversationFetchResult::quotaExhausted('credits depleted'));

    $fresh = ConnectedAccount::withoutGlobalScopes()->findOrFail($account->id);

    expect($fresh->messaging_rate_limited_until->timestamp)
        ->toBe(now()->addSeconds(21600)->timestamp);
});

test('a quota park is never shorter than the configured floor', function () {
    $this->freezeTime();
    config()->set('messages.quota_exhausted_backoff', 21600);

    $account = quotaExhaustedAccount();

    // Even if the platform suggests a short retry-after, quota does not refill
    // on that timescale — the floor wins.
    runMessagesJobWith($account, ConversationFetchResult::quotaExhausted('credits depleted', 60));

    $fresh = ConnectedAccount::withoutGlobalScopes()->findOrFail($account->id);

    expect($fresh->messaging_rate_limited_until->timestamp)
        ->toBe(now()->addSeconds(21600)->timestamp);
});

test('a plain rate limit still uses the short rate-limit backoff', function () {
    $this->freezeTime();

    $account = quotaExhaustedAccount();

    runMessagesJobWith($account, ConversationFetchResult::rateLimited('slow down', 120));

    $fresh = ConnectedAccount::withoutGlobalScopes()->findOrFail($account->id);

    expect($fresh->messaging_rate_limited_until->timestamp)
        ->toBe(now()->addSeconds(120)->timestamp);
});
