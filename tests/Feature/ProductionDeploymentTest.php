<?php

use App\Jobs\PublishPostTarget;
use App\Models\PostTarget;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->deploymentDirectory = sys_get_temp_dir().'/calander-deployment-'.Str::uuid();
    File::makeDirectory($this->deploymentDirectory);
});

afterEach(function (): void {
    File::deleteDirectory($this->deploymentDirectory);
});

test('container initialization supports the default sqlite connection and is repeatable', function (): void {
    $database = $this->deploymentDirectory.'/database/database.sqlite';
    $process = new Process(
        ['bash', base_path('docker/entrypoint.d/10-init-app.sh')],
        $this->deploymentDirectory,
        ['DB_CONNECTION' => false, 'DB_DATABASE' => $database],
    );

    $process->mustRun();

    expect(is_file($database))->toBeTrue()
        ->and(is_dir($this->deploymentDirectory.'/storage/app/private'))->toBeTrue()
        ->and(is_dir($this->deploymentDirectory.'/storage/app/public'))->toBeTrue();

    file_put_contents($database, 'existing database');
    $process->mustRun();

    expect(file_get_contents($database))->toBe('existing database');
});

test('container initialization does not create sqlite files for memory or remote databases', function (string $connection, string $database): void {
    $process = new Process(
        ['bash', base_path('docker/entrypoint.d/10-init-app.sh')],
        $this->deploymentDirectory,
        ['DB_CONNECTION' => $connection, 'DB_DATABASE' => $database],
    );

    $process->mustRun();

    expect(is_file($this->deploymentDirectory.'/'.$database))->toBeFalse();
})->with([
    'memory sqlite' => ['sqlite', ':memory:'],
    'postgres' => ['pgsql', 'calander'],
]);

test('production environment example starts with safe explicit application settings', function (): void {
    $environment = Dotenv::parse(File::get(base_path('.env.example.prod')));

    expect($environment)
        ->toMatchArray([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => '',
            'SESSION_SECURE_COOKIE' => 'true',
            'ALLOW_DEFAULT_USER_SEED' => 'false',
            'SELF_HOSTED' => 'true',
        ]);
});

test('production smtp example constructs a supported transport', function (): void {
    preg_match('/^# MAIL_SCHEME=(.+)$/m', File::get(base_path('.env.example.prod')), $matches);
    config(['mail.mailers.smtp.scheme' => $matches[1] ?? null]);

    expect(Mail::mailer('smtp')->getSymfonyTransport())->toBeInstanceOf(EsmtpTransport::class);
});

test('production supervisor configuration has explicit permissions for its unprivileged runtime', function (): void {
    preg_match(
        '/^COPY\s+([^\n]*?)\s+docker\/supervisord\.conf\s+\/etc\/supervisor\/laravel\.conf\s*$/m',
        File::get(base_path('Dockerfile')),
        $copy,
    );
    preg_match('/--chmod=([0-7]{3,4})(?:\s|$)/', $copy[1] ?? '', $mode);
    $permissions = octdec($mode[1] ?? '0');

    expect($permissions & 0004)->toBe(0004)
        ->and($permissions & 0022)->toBe(0);
});

test('supervisor allows a publishing job to finish during a deployment', function (): void {
    preg_match(
        '/\[program:worker\](.*?)(?=\n\[|\z)/s',
        File::get(base_path('docker/supervisord.conf')),
        $worker,
    );
    preg_match('/^stopwaitsecs=(\d+)$/m', $worker[1] ?? '', $gracePeriod);

    expect((int) ($gracePeriod[1] ?? 0))->toBeGreaterThan((new PublishPostTarget(PostTarget::factory()->make()))->timeout);
});
