<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;

test('markdown notification emails use the SM Manager brand', function () {
    config([
        'app.name' => 'SM Manager',
        'app.url' => 'https://sm-manager.test',
    ]);

    $user = User::factory()->unverified()->create();

    $html = (string) (new VerifyEmail)->toMail($user)->render();

    expect($html)->toContain('https://sm-manager.test/shoutrrr.png');
    expect($html)->toContain('alt="SM Manager Logo"');
    expect($html)->toContain('#7dd000');
    expect($html)->not->toContain('laravel.com/img/notification-logo');
    expect($html)->not->toContain('Laravel Logo');
});
