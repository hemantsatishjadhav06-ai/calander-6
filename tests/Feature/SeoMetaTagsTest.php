<?php

/**
 * The app shell is the only surface a non-user meets (login links get pasted
 * into chat constantly), so it has to carry real preview metadata rather than
 * unfurling as a bare URL.
 */
test('the app shell renders description, canonical and social preview tags', function (): void {
    config([
        'app.name' => 'SM Manager',
        'app.tagline' => 'Write once, publish everywhere',
        'app.description' => 'Schedule and publish from one calendar.',
        'app.url' => 'https://sm-manager.test',
    ]);

    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="description" content="Schedule and publish from one calendar.">')
        ->toContain('<meta property="og:type" content="website">')
        ->toContain('<meta property="og:site_name" content="SM Manager">')
        ->toContain('<meta property="og:title" content="SM Manager — Write once, publish everywhere">')
        ->toContain('<meta property="og:description" content="Schedule and publish from one calendar.">')
        ->toContain('<meta property="og:image" content="https://sm-manager.test/android-chrome-512x512.png">')
        ->toContain('<meta name="twitter:card" content="summary">')
        ->toContain('<meta name="twitter:title" content="SM Manager — Write once, publish everywhere">')
        ->toContain('<link rel="canonical"');
});

test('the app shell never falls back to the framework name in the title', function (): void {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->not->toContain('<title>Laravel</title>');
});
