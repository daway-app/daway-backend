<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class StorageLinkInDockerTest extends TestCase
{
    private function startupCommand(): string
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
        preg_match_all('/^CMD\s+(\[.*\])\s*$/m', $dockerfile, $matches);
        $this->assertCount(1, $matches[1]);

        $command = json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['sh', '-c'], array_slice($command, 0, 2));

        return $command[2];
    }

    public static function startupScenarios(): array
    {
        $config = 'artisan config:cache';
        $route = 'artisan route:cache';
        $storage = 'artisan storage:link';
        $serve = 'artisan serve --host=0.0.0.0 --port=10000';

        return [
            'config failure stops startup' => ['config-failure', 17, [$config]],
            'route failure stops startup' => ['route-failure', 18, [$config, $route]],
            'valid relative link skips creation' => ['valid-link', 0, [$config, $route, $serve]],
            'valid absolute link skips creation' => ['absolute-link', 0, [$config, $route, $serve]],
            'storage failure stops startup' => ['storage-failure', 19, [$config, $route, $storage]],
            'zero exit without a link still fails' => ['silent-failure', 1, [$config, $route, $storage]],
            'wrong target still fails' => ['wrong-link', 1, [$config, $route, $storage]],
            'regular path is not a valid link' => ['regular-path', 1, [$config, $route, $storage]],
            'dangling link does not hide failure' => ['dangling-link', 19, [$config, $route, $storage]],
            'missing target directory still fails' => ['missing-target', 1, [$config, $route, $storage]],
            'readlink failure stops startup' => ['readlink-failure', 1, [$config, $route, $storage]],
            'successful preparation starts Laravel' => ['success', 0, [$config, $route, $storage, $serve]],
            'Render port is preserved' => ['custom-port', 0, [$config, $route, $storage, 'artisan serve --host=0.0.0.0 --port=15432']],
        ];
    }

    #[DataProvider('startupScenarios')]
    public function test_startup_behaviour(string $scenario, int $exitCode, array $calls): void
    {
        $shell = PHP_OS_FAMILY === 'Windows'
            ? (getenv('ProgramFiles') ?: 'C:/Program Files').'/Git/usr/bin/sh.exe'
            : (new ExecutableFinder)->find('sh');

        $this->assertNotEmpty($shell, 'A POSIX shell is required to test the Docker CMD.');
        $this->assertFileExists($shell);

        // Execute the actual CMD in an isolated directory with real links.
        // Only artisan is stubbed; no Laravel server, database, HTTP or sleep runs.
        $harness = <<<'SH'
set -eu
export PATH="/usr/bin:/bin:$PATH"
work=$(mktemp -d)
original=$PWD
cleanup() {
    cd "$original"
    rm -f "$work/public/storage" "$work/bin/php" "$work/bin/readlink" "$work/calls"
    rmdir "$work/public" "$work/storage/app/public" "$work/storage/app" "$work/storage" "$work/bin" "$work"
}
trap cleanup 0
mkdir -p "$work/bin" "$work/public" "$work/storage/app/public"
cd "$work"
export PATH="$work/bin:$PATH" CALL_LOG="$work/calls"
: > "$CALL_LOG"
case "$SCENARIO" in
    valid-link|readlink-failure) ln -s ../storage/app/public public/storage ;;
    absolute-link) ln -s "$work/storage/app/public" public/storage ;;
    wrong-link) ln -s ../bin public/storage ;;
    regular-path) : > public/storage ;;
    dangling-link) ln -s ../storage/missing public/storage ;;
    missing-target) rmdir storage/app/public ;;
esac
if [ "$SCENARIO" = readlink-failure ]; then
    printf '#!/bin/sh\nexit 1\n' > bin/readlink
    chmod +x bin/readlink
fi
cat > bin/php <<'PHP_STUB'
#!/bin/sh
printf '%s\n' "$*" >> "$CALL_LOG"
[ "$1" = artisan ] || exit 90
case "$2" in
    config:cache) [ "$SCENARIO" != config-failure ] || exit 17 ;;
    route:cache) [ "$SCENARIO" != route-failure ] || exit 18 ;;
    storage:link)
        case "$SCENARIO" in
            storage-failure|dangling-link|valid-link|absolute-link) exit 19 ;;
            silent-failure|wrong-link|regular-path|readlink-failure) exit 0 ;;
            *) ln -s ../storage/app/public public/storage ;;
        esac ;;
    serve) exit 0 ;;
    *) exit 90 ;;
esac
PHP_STUB
chmod +x bin/php
set +e
sh -c 'curl() { return 0; }; sleep() { exit 0; }; eval "$STARTUP_COMMAND"'
status=$?
# The missing-target scenario intentionally removed this directory.
mkdir -p storage/app/public
cat "$CALL_LOG"
exit "$status"
SH;

        $process = new Process([$shell, '-c', str_replace("\r\n", "\n", $harness)], env: [
            'STARTUP_COMMAND' => $this->startupCommand(),
            'SCENARIO' => $scenario,
            'PORT' => $scenario === 'custom-port' ? '15432' : false,
            // MSYS links work without Windows' native symlink privilege.
            'MSYS' => 'winsymlinks',
        ]);
        $process->setTimeout(10);
        $process->run();

        $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput());
        $actualCalls = preg_split('/\R/', trim($process->getOutput()));
        $this->assertSame($calls, $actualCalls, $process->getErrorOutput());
    }

    public function test_dockerfile_does_not_run_migrations_or_seeders(): void
    {
        $command = $this->startupCommand();

        $this->assertStringNotContainsString('migrate', $command);
        $this->assertStringNotContainsString('db:seed', $command);
    }
}
