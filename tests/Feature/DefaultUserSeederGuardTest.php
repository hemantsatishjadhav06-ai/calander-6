<?php

use App\Models\User;
use Database\Seeders\DefaultUserSeeder;

/**
 * The seeder creates a known-password instance owner, so it must never run on a
 * deployed instance. It is invoked directly by dev tooling and deploy
 * pre-commands, which bypasses DatabaseSeeder's isLocal() guard.
 */
test('the default user seeder refuses to run outside local and testing', function () {
    app()->detectEnvironment(fn (): string => 'production');

    (new DefaultUserSeeder)->run();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

test('the default user seeder can be forced with an explicit opt-in', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['instance.allow_default_user_seed' => true]);

    (new DefaultUserSeeder)->run();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});

test('the default user seeder still seeds in the testing environment', function () {
    (new DefaultUserSeeder)->run();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});
