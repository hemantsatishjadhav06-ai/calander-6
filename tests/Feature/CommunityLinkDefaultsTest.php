<?php

declare(strict_types=1);

/**
 * The sidebar's community links previously defaulted to the upstream project
 * this app was forked from, so every logged-in customer was invited to star and
 * financially sponsor a different organisation. These guard the defaults, which
 * the other community tests do not because they set config explicitly.
 */
test('community links do not default to the upstream project', function (): void {
    expect(config('instance.community.repo'))->not->toContain('coollabsio');
    expect(config('instance.community.sponsor_url'))->not->toContain('coollabsio');
});

test('sponsorship is opt-in rather than pointed at someone by default', function (): void {
    expect(config('instance.community.sponsor_url'))->toBe('');
});

test('the repo default is this product own repository', function (): void {
    expect(config('instance.community.repo'))->toBe('hemantsatishjadhav06-ai/calander-6');
});
