<?php

use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The product pages explain what the app does and how. Their numbers are read
 * from the enum, the config and the MCP server rather than typed into the page,
 * so these tests pin that wiring: a page that quotes a limit the app does not
 * enforce is worse than no page.
 */
test('the product pages stay readable for a signed-in user', function (string $path, string $component) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/features', 'public/features'],
    ['/how-it-works', 'public/how-it-works'],
    ['/platforms', 'public/platforms'],
    ['/developers', 'public/developers'],
    ['/security', 'public/security'],
]);

test('every public page links to the configured source repository', function (string $path) {
    config()->set('instance.community.repo', 'acme/social');

    $this->get($path)->assertInertia(fn (Assert $page) => $page
        ->where('repoUrl', 'https://github.com/acme/social'));
})->with(['/', '/features', '/security', '/privacy']);

test('the source link is hidden when no repository is configured', function () {
    config()->set('instance.community.repo', '');

    $this->get('/features')->assertInertia(fn (Assert $page) => $page->where('repoUrl', ''));
});

test('the platforms page quotes the limits the composer enforces', function () {
    $this->get('/platforms')->assertInertia(function (Assert $page): void {
        $page->has('platforms', count(Platform::cases()));

        foreach (Platform::cases() as $index => $platform) {
            $page->where("platforms.{$index}.value", $platform->value)
                ->where("platforms.{$index}.maxLength", $platform->maxLength())
                ->where("platforms.{$index}.maxMedia", $platform->maxMedia())
                ->where("platforms.{$index}.maxVideoSeconds", $platform->maxVideoDurationSeconds())
                ->where("platforms.{$index}.threads", $platform->threadMax() === null)
                ->where("platforms.{$index}.requiresMedia", $platform->requiresMedia())
                ->where("platforms.{$index}.directMessages", $platform->supportsDirectMessages())
                ->where("platforms.{$index}.autoRepost", $platform->supportsRepost());
        }
    });
});

test('the platforms page describes how each network connects', function () {
    $this->get('/platforms')->assertInertia(fn (Assert $page) => $page
        ->where('platforms.'.array_search(Platform::Discord, Platform::cases(), true).'.connection', 'webhook')
        ->where('platforms.'.array_search(Platform::Bluesky, Platform::cases(), true).'.connection', 'app-password')
        ->where('platforms.'.array_search(Platform::X, Platform::cases(), true).'.connection', 'oauth')
        ->where('platforms.'.array_search(Platform::X, Platform::cases(), true).'.lengthUnit', 'utf16')
        ->where('platforms.'.array_search(Platform::Bluesky, Platform::cases(), true).'.lengthUnit', 'graphemes'));
});

test('the developers page lists every tool the MCP server exposes', function () {
    /** @var list<class-string> $toolClasses */
    $toolClasses = (new ReflectionClass(ShoutrrrServer::class))->getProperty('tools')->getDefaultValue();
    $expectedNames = array_map(fn (string $class): string => app($class)->name(), $toolClasses);

    $this->get('/developers')->assertInertia(fn (Assert $page) => $page
        ->has('mcpTools', count($toolClasses))
        ->where('mcpTools', fn ($tools): bool => collect($tools)->pluck('name')->all() === $expectedNames)
        ->where('mcpTools', fn ($tools): bool => collect($tools)->every(fn (array $tool): bool => $tool['description'] !== '')));
});

test('the developers page shows the endpoints of the instance it is served from', function () {
    $this->get('https://social.example.test/developers')->assertInertia(fn (Assert $page) => $page
        ->where('apiBaseUrl', 'https://social.example.test/api/v1')
        ->where('mcpUrl', 'https://social.example.test/mcp'));
});

test('the home page counts the MCP tools it advertises', function () {
    $toolCount = count((new ReflectionClass(ShoutrrrServer::class))->getProperty('tools')->getDefaultValue());

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('mcpToolCount', $toolCount));
});

test('the how-it-works page reads its polling and repost numbers from config', function () {
    config()->set('engagement.reply_refresh', [
        ['max_age_hours' => 12, 'interval_minutes' => 20],
    ]);
    config()->set('engagement.steady_interval_minutes', 720);
    config()->set('metrics.post_refresh', [
        ['max_age_hours' => 48, 'interval_minutes' => 90],
    ]);
    config()->set('repost.defaults.min_delay_hours', 36);
    config()->set('repost.defaults.min_percentile', 0.75);

    $this->get('/how-it-works')->assertInertia(fn (Assert $page) => $page
        ->where('cadence.replies', [['max_age_hours' => 12, 'interval_minutes' => 20]])
        ->where('cadence.repliesSteadyMinutes', 720)
        ->where('cadence.metrics', [['max_age_hours' => 48, 'interval_minutes' => 90]])
        ->where('repost.minDelayHours', 36)
        ->where('repost.minPercentile', 0.75));
});
