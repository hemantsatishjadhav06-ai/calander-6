<?php

use App\Enums\Platform;
use App\Http\Controllers\ConnectedAccounts\MetaConnectionController;
use App\Http\Controllers\ConnectedAccounts\OAuthConnectionController;
use App\Support\InstanceSettings;

// scopes() is private; reach it the same way DirectMessageScopeOptInTest does
// for OAuthConnectionController::scopesFor().

test('meta scopes include ig and fb dm scopes when direct messages enabled', function () {
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    config()->set('messages.direct_messages_enabled', true);

    $controller = app(MetaConnectionController::class);
    $scopes = (fn () => $this->scopes())->call($controller);

    expect($scopes)
        ->toContain('instagram_manage_messages')
        ->toContain('pages_messaging')
        ->toContain('pages_manage_metadata')
        ->toContain('pages_show_list');
});

test('meta scopes exclude ig and fb dm scopes when direct messages disabled', function () {
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    config()->set('messages.direct_messages_enabled', false);

    $controller = app(MetaConnectionController::class);
    $scopes = (fn () => $this->scopes())->call($controller);

    expect($scopes)
        ->not->toContain('instagram_manage_messages')
        ->not->toContain('pages_messaging')
        ->not->toContain('pages_manage_metadata')
        ->toContain('pages_show_list')
        ->toContain('pages_manage_posts')
        ->toContain('instagram_content_publish');
});

test('messaging permission dependencies follow the opt-in in the mirrored oauth helper', function (Platform $platform, bool $enabled) {
    config()->set('messages.direct_messages_enabled', $enabled);

    $controller = app(OAuthConnectionController::class);
    $scopes = (fn () => $this->scopesFor($platform))->call($controller);
    $messagingScope = $platform === Platform::Facebook ? 'pages_messaging' : 'instagram_manage_messages';
    $publishingScope = $platform === Platform::Facebook ? 'pages_manage_posts' : 'instagram_content_publish';

    expect($scopes)->toContain('pages_show_list')->toContain($publishingScope);

    if ($enabled) {
        expect($scopes)->toContain($messagingScope)->toContain('pages_manage_metadata');
    } else {
        expect($scopes)->not->toContain($messagingScope)->not->toContain('pages_manage_metadata');
    }
})->with([
    'facebook messaging enabled' => [Platform::Facebook, true],
    'facebook messaging disabled' => [Platform::Facebook, false],
    'instagram messaging enabled' => [Platform::Instagram, true],
    'instagram messaging disabled' => [Platform::Instagram, false],
]);

test('instagram-only meta connections retain messaging dependencies only when opted in', function (bool $enabled) {
    config()->set('messages.direct_messages_enabled', $enabled);
    app(InstanceSettings::class)->update(['platforms_enabled' => ['facebook' => false, 'instagram' => true]]);

    expect(Platform::availableMetaGraphPlatforms())->toBe([Platform::Instagram]);

    $controller = app(MetaConnectionController::class);
    $scopes = (fn () => $this->scopes())->call($controller);

    expect($scopes)->toContain('instagram_basic')
        ->toContain('instagram_content_publish')
        ->toContain('pages_show_list')
        ->not->toContain('pages_messaging');

    if ($enabled) {
        expect($scopes)->toContain('instagram_manage_messages')->toContain('pages_manage_metadata');
    } else {
        expect($scopes)->not->toContain('instagram_manage_messages')->not->toContain('pages_manage_metadata');
    }
})->with([true, false]);

test('buildAccountData sets dm_enabled true for instagram alongside page_id when enabled', function () {
    config()->set('messages.direct_messages_enabled', true);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => 'IG1',
        'igUsername' => 'myig',
        'igAvatarUrl' => null,
    ], Platform::Instagram);

    expect($data->capabilities)->toMatchArray([
        'page_id' => 'PAGE1',
        'dm_enabled' => true,
    ]);
});

test('buildAccountData sets dm_enabled false for instagram when disabled, keeping page_id', function () {
    config()->set('messages.direct_messages_enabled', false);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => 'IG1',
        'igUsername' => 'myig',
        'igAvatarUrl' => null,
    ], Platform::Instagram);

    expect($data->capabilities)->toMatchArray([
        'page_id' => 'PAGE1',
        'dm_enabled' => false,
    ]);
});

test('buildAccountData sets dm_enabled true for facebook when enabled', function () {
    config()->set('messages.direct_messages_enabled', true);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => null,
        'igUsername' => null,
        'igAvatarUrl' => null,
    ], Platform::Facebook);

    expect($data->capabilities)->toMatchArray(['dm_enabled' => true]);
});

test('buildAccountData sets dm_enabled false for facebook when disabled', function () {
    config()->set('messages.direct_messages_enabled', false);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => null,
        'igUsername' => null,
        'igAvatarUrl' => null,
    ], Platform::Facebook);

    expect($data->capabilities)->toMatchArray(['dm_enabled' => false]);
});
