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

function containerEntrypointProcess(string $directory, string $userId, array $environment = [], bool $outputToFiles = false): Process
{
    $bin = $directory.'/bin';
    File::makeDirectory($bin);

    $commands = [
        'id' => 'printf "%s\\n" "$SM_ENTRYPOINT_TEST_UID"',
        'chown' => <<<'SH'
if [ "${SM_ENTRYPOINT_TEST_CHOWN_FAILURE:-false}" = "true" ]; then exit 17; fi
printf '%s\n' "$@" >> "$SM_ENTRYPOINT_TEST_DIRECTORY/ownership-arguments"
SH,
        'setpriv' => <<<'SH'
printf '%s\n' "$@" > "$SM_ENTRYPOINT_TEST_DIRECTORY/privilege-arguments"
while [ "$1" != "--" ]; do shift; done
shift
exec "$@"
SH,
        'docker-php-serversideup-entrypoint' => <<<'SH'
printf '%s\n' "$@"
SH,
    ];

    foreach ($commands as $name => $command) {
        $path = $bin.'/'.$name;
        File::put($path, "#!/bin/sh\nset -eu\n".$command."\n");
        chmod($path, 0755);
    }

    $command = $outputToFiles
        ? ['sh', '-c', 'exec sh "$1" php "argument with spaces" --flag >"$2" 2>"$3"', 'entrypoint-fixture', base_path('docker/entrypoint.sh'), $directory.'/stdout', $directory.'/stderr']
        : ['sh', base_path('docker/entrypoint.sh'), 'php', 'argument with spaces', '--flag'];

    return new Process(
        $command,
        $directory,
        [
            'PATH' => $bin.':'.getenv('PATH'),
            'APP_BASE_DIR' => $directory.'/app',
            'SM_ENTRYPOINT_TEST_DIRECTORY' => $directory,
            'SM_ENTRYPOINT_TEST_UID' => $userId,
            ...$environment,
        ],
    );
}

test('root container startup preserves existing files and drops privileges before initialization', function (): void {
    $storage = $this->deploymentDirectory.'/app/storage';
    File::makeDirectory($storage, 0755, true);
    $key = $storage.'/oauth-private.key';
    File::put($key, 'private key fixture');
    chmod($key, 0600);

    $process = containerEntrypointProcess($this->deploymentDirectory, '0');
    $process->mustRun();

    expect(File::get($key))->toBe('private key fixture')
        ->and(fileperms($key) & 0777)->toBe(0600)
        ->and(is_dir($this->deploymentDirectory.'/app/bootstrap/cache'))->toBeTrue()
        ->and(File::get($this->deploymentDirectory.'/privilege-arguments'))->toBe(
            "--reuid=www-data\n--regid=www-data\n--init-groups\n--no-new-privs\n--\ndocker-php-serversideup-entrypoint\nphp\nargument with spaces\n--flag\n",
        )
        ->and($process->getOutput())->toBe("php\nargument with spaces\n--flag\n");
});

test('non-root container startup forwards the command without changing volume ownership', function (): void {
    $process = containerEntrypointProcess($this->deploymentDirectory, '9999');
    $process->mustRun();

    expect($process->getOutput())->toBe("php\nargument with spaces\n--flag\n")
        ->and(is_dir($this->deploymentDirectory.'/app'))->toBeFalse()
        ->and(is_file($this->deploymentDirectory.'/privilege-arguments'))->toBeFalse();
});

test('root container startup prepares both console pipes before dropping privileges', function (): void {
    $process = containerEntrypointProcess($this->deploymentDirectory, '0');
    $process->mustRun();

    expect(File::get($this->deploymentDirectory.'/ownership-arguments'))
        ->toContain("/proc/self/fd/1\n", "/proc/self/fd/2\n")
        ->and($process->getOutput())->toBe("php\nargument with spaces\n--flag\n");
});

test('root container startup leaves redirected regular console files untouched', function (): void {
    foreach (['stdout', 'stderr'] as $name) {
        File::put($this->deploymentDirectory.'/'.$name, '');
        chmod($this->deploymentDirectory.'/'.$name, 0600);
    }

    $process = containerEntrypointProcess($this->deploymentDirectory, '0', outputToFiles: true);
    $process->mustRun();

    expect(File::get($this->deploymentDirectory.'/ownership-arguments'))
        ->not->toContain('/proc/self/fd/1', '/proc/self/fd/2')
        ->and(File::get($this->deploymentDirectory.'/stdout'))->toBe("php\nargument with spaces\n--flag\n")
        ->and(fileperms($this->deploymentDirectory.'/stdout') & 0777)->toBe(0600)
        ->and(fileperms($this->deploymentDirectory.'/stderr') & 0777)->toBe(0600);
});

test('root container startup fails before initialization when volume ownership cannot be prepared', function (): void {
    $process = containerEntrypointProcess($this->deploymentDirectory, '0', [
        'SM_ENTRYPOINT_TEST_CHOWN_FAILURE' => 'true',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(17)
        ->and($process->getOutput())->toBe('')
        ->and(is_file($this->deploymentDirectory.'/privilege-arguments'))->toBeFalse();
});

test('root container startup refuses symlinked writable directories', function (): void {
    $target = $this->deploymentDirectory.'/preserved';
    File::makeDirectory($target);
    File::makeDirectory($this->deploymentDirectory.'/app');
    symlink($target, $this->deploymentDirectory.'/app/storage');

    $process = containerEntrypointProcess($this->deploymentDirectory, '0');
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('must not be symlinks')
        ->and(is_file($this->deploymentDirectory.'/privilege-arguments'))->toBeFalse();
});

test('root container startup rejects unsafe application directory settings', function (string $directory): void {
    $process = containerEntrypointProcess($this->deploymentDirectory, '0', ['APP_BASE_DIR' => $directory]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('APP_BASE_DIR must')
        ->and(is_file($this->deploymentDirectory.'/privilege-arguments'))->toBeFalse();
})->with([
    'filesystem root' => '/',
    'relative path' => 'relative/path',
]);
