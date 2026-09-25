<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `/` used to bounce guests straight to the login form. It now serves the
 * public marketing page, which is what an OAuth reviewer, a search crawler and
 * a first-time visitor each expect to find there.
 */
test('guests get the public home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/home'));
});

test('authenticated users are redirected from home to dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
