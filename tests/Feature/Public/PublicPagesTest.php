<?php

use App\Enums\Platform;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * These pages are the reason social sign-in can exist at all: Google, Meta, X
 * and LinkedIn each require a reachable privacy policy before approving an
 * OAuth app, and Meta additionally requires a data-deletion URL. If any of them
 * falls behind auth or 404s, app review fails.
 */
test('every public page is reachable without an account', function (string $path, string $component) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/', 'public/home'],
    ['/features', 'public/features'],
    ['/how-it-works', 'public/how-it-works'],
    ['/platforms', 'public/platforms'],
    ['/developers', 'public/developers'],
    ['/security', 'public/security'],
    ['/privacy', 'public/privacy'],
    ['/terms', 'public/terms'],
    ['/data-deletion', 'public/data-deletion'],
]);

/**
 * The legal pages must not sit behind the `verified` middleware either: a user
 * who signed up and has not clicked the verification link is exactly the person
 * most likely to go looking for the privacy policy.
 */
test('the legal pages are reachable by a signed-in but unverified user', function (string $path) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get($path)->assertOk();
})->with(['/privacy', '/terms', '/data-deletion']);

test('the home page sends signed-in users to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('the home page lists every platform the app can publish to', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->component('public/home')
        ->has('platforms', count(Platform::cases()))
        ->where('platforms.0.label', Platform::cases()[0]->label()));
});

test('the home page reflects whether registrations are open', function () {
    config()->set('instance.defaults.registrations_enabled', false);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('registrationsEnabled', false));

    config()->set('instance.defaults.registrations_enabled', true);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('registrationsEnabled', true));
});

test('the legal pages carry the operator details configured for the instance', function (string $path) {
    config()->set('instance.legal', [
        'company' => 'Neopolis Infra LLP',
        'contact_email' => 'privacy@example.test',
        'address' => '1 Example Street',
        'jurisdiction' => 'India',
        'effective_date' => '2026-01-01',
    ]);

    $this->get($path)->assertInertia(fn (Assert $page) => $page
        ->where('company', 'Neopolis Infra LLP')
        ->where('contactEmail', 'privacy@example.test')
        ->where('address', '1 Example Street')
        ->where('jurisdiction', 'India')
        ->where('effectiveDate', '2026-01-01'));
})->with(['/privacy', '/terms', '/data-deletion']);

/**
 * An unset company or contact address is passed through empty rather than
 * defaulted. A policy that names a company which never agreed to it, or an
 * address nobody reads, is worse than a page that admits nothing is configured.
 */
test('unconfigured operator details are not invented', function () {
    config()->set('instance.legal', [
        'company' => '',
        'contact_email' => '',
        'address' => '',
        'jurisdiction' => '',
        'effective_date' => '2026-01-01',
    ]);

    $this->get('/privacy')->assertInertia(fn (Assert $page) => $page
        ->where('company', '')
        ->where('contactEmail', ''));
});
