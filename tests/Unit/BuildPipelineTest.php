<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

test('the regression and lint workflows run on feature branch pushes', function (string $workflow) {
    $config = Yaml::parseFile(base_path(".github/workflows/{$workflow}.yml"));

    expect($config['on']['push']['branches'])->toContain('feat/**')
        ->and($config['on']['pull_request']['branches'])->toContain('main');
})->with(['tests', 'lint']);

test('the regression workflow gates on types and frontend tests after generating assets', function () {
    $config = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $steps = collect($config['jobs']['tests']['steps']);
    $commands = $steps->pluck('run')->filter()->values()->all();

    expect($commands)->toContain('bun install --frozen-lockfile', 'bun run build', 'bun run types:check', 'bun run test', 'php artisan test --parallel')
        ->and(array_search('bun run build', $commands, true))->toBeLessThan(array_search('bun run types:check', $commands, true));

    foreach (['bun run types:check', 'bun run test'] as $command) {
        $step = $steps->firstWhere('run', $command);

        expect($step['continue-on-error'] ?? false)->toBeFalse()
            ->and($step)->not->toHaveKey('if');
    }
});

test('the local aggregate gate includes the frontend regression suite', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['ci:check'])->toContain('bun run types:check', 'bun run test', '@test');
});

test('the lint workflow checks PHP formatting without rewriting files', function () {
    $config = Yaml::parseFile(base_path('.github/workflows/lint.yml'));
    $commands = collect($config['jobs']['quality']['steps'])->pluck('run')->filter()->all();

    expect($commands)->toContain('composer lint:check')
        ->not->toContain('composer lint');
});

test('production page discovery does not include frontend test modules', function () {
    $testModules = collect(File::allFiles(resource_path('js/pages')))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/\.(test|spec)\.[jt]sx$/', $file->getFilename()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->values()
        ->all();

    expect($testModules)->toBeEmpty();
});
