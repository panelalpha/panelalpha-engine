<?php

namespace Tests\Unit\Deploy\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The host PHP build resolves for the engine's chosen PHP minor, not for the
 * project's own `config.platform`.
 *
 * Two Composer passes run on the host and only one pinned the platform.
 * `composerInstallArgv()` pinned; `runPhpBuild()` -- the pass that actually
 * produces the `vendor/` the application runs on -- did not, so the project's
 * pin won. YOURLS is how that was found: `config.platform` 8.1.0 beside
 * `require.php ^8.4`, so the engine built the 8.4 base image and then failed
 * the resolve against 8.1 with "overridden via config.platform, actual:
 * 8.4.25". htmly ships the same shape pinned at 7.2.
 *
 * The real `runPhpBuild()` is driven here end to end: the manifests are read
 * through the account's own file API and the argv comes back from the real
 * builder, so the guard that keeps a build inside ~/project is exercised too.
 */
class HostCompilePhpPlatformPinTest extends TestCase
{
    /** @var array<string, string> absolute account path => contents */
    private array $files = [];

    /** @var array<string, string> target path => contents, from every `sudo cp` the build issued */
    private array $copiedTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = [];
        $this->copiedTo = [];
    }

    /**
     * Run the real host PHP build against a project whose files are the map
     * given, and answer the argv of the container it started.
     *
     * @param array<string, string> $files path under the project => contents
     * @param array<string, mixed> $decision the matched manifest's, as
     *        DetectProjectStrategy would hand it over
     */
    private function build(array $files, array $decision, string $appRoot = '', bool $hasComposer = true): ?array
    {
        foreach ($files as $path => $contents) {
            $this->files['/home/acme/project/' . ltrim($path, '/')] = $contents;
        }

        $filesystem = $this->files;
        $copiedTo = &$this->copiedTo;

        $system = new class ($filesystem, $copiedTo) extends System {
            /** @var array<string, string> */
            private array $files;

            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files
             *  @param array<string, string> $copiedTo */
            public function __construct(array $files, private array &$copiedTo)
            {
                $this->files = $files;
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;
                $engine = $this;

                return new class ($engine, $files) extends SystemFilesystem {
                    /** @param array<string, string> $files */
                    public function __construct(System $engine, private array $files)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? '';
                    }
                };
            }

            /**
             * Real writes go through `Filesystem::filePutContents()`, which
             * stages the content in a real temp file and copies it out with
             * `sudo cp <tmp> <target>`. Reading the temp file here, before the
             * real filePutContents() unlinks it, is how the test observes what
             * was actually written without a filesystem the account owns.
             */
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (preg_match('/^sudo cp (\S+) (\S+)$/', $line, $m) === 1 && is_file($m[1])) {
                    $this->copiedTo[$m[2]] = (string) file_get_contents($m[1]);
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? $cmd : [$cmd];
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };

        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder());

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        // The real reader, over the stubbed System's file map, so the minor is
        // resolved from the same composer.json the deploy would read.
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        (new HostCompile($dind))->runPhpBuild(
            'panelalpha/php:8.4-apache-bookworm-pa20260910',
            $decision,
            $appRoot,
            $hasComposer
        );

        // The first command is the cache preparation; the build container is
        // the last thing the pass runs.
        if ($system->commands === []) {
            return null;
        }

        return end($system->commands) ?: null;
    }

    private function script(?array $argv): string
    {
        $this->assertNotNull($argv, 'the host PHP build ran nothing');

        return (string) end($argv);
    }

    /** @return array<string, mixed> */
    private function phpDecision(): array
    {
        return [
            'install_command' => 'composer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader',
            'build_command' => '',
        ];
    }

    /**
     * YOURLS: the project pins 8.1.0 and requires ^8.4. The engine resolves 8.4
     * from the requirement and builds the 8.4 image, so 8.4 is what the resolve
     * has to run against.
     */
    public function test_the_engines_minor_wins_over_the_projects_own_platform_pin(): void
    {
        $script = $this->script($this->build([
            'composer.json' => (string) json_encode([
                'require' => ['php' => '^8.4'],
                'config' => ['platform' => ['php' => '8.1.0']],
            ]),
        ], $this->phpDecision()));

        $this->assertStringContainsString('composer config --no-plugins platform.php 8.4.99', $script);
        $this->assertStringNotContainsString('8.1', $script);
        $this->assertStringContainsString('composer install --no-dev', $script);
    }

    /**
     * htmly declares `config.platform` 7.2 and nothing that pins a minor --
     * and 7.2 is not a minor the engine ships, so the deploy runs on whatever
     * the resolver answers and the resolve has to use that same answer.
     */
    public function test_a_project_that_only_pins_its_platform_resolves_for_the_engines_answer(): void
    {
        $composerJson = (string) json_encode([
            'require' => ['php' => '>=7.2'],
            'config' => ['platform' => ['php' => '7.2']],
        ]);

        $minor = PhpRuntime::requirementFor($composerJson)->version;
        $script = $this->script($this->build(['composer.json' => $composerJson], $this->phpDecision()));

        $this->assertStringContainsString('composer config --no-plugins platform.php ' . $minor . '.99', $script);
        $this->assertStringNotContainsString('7.2', $script);
    }

    /**
     * phpBB declares `app_root: phpBB`, and the application's composer.json --
     * the one with the PHP requirement -- is in that subtree. Reading the
     * repository root instead would find nothing and pin nothing.
     */
    public function test_the_minor_is_read_from_the_manifests_app_root(): void
    {
        $script = $this->script($this->build([
            'phpBB/composer.json' => (string) json_encode([
                'require' => ['php' => '^8.2'],
                'config' => ['platform' => ['php' => '7.4']],
            ]),
        ], $this->phpDecision(), 'phpBB'));

        $this->assertStringContainsString('composer config --no-plugins platform.php 8.2.99', $script);
        $this->assertStringNotContainsString('7.4', $script);
    }

    /**
     * osTicket vendors its dependencies and ships no composer.json. There is no
     * version to resolve for, so the install is left exactly as declared.
     */
    public function test_a_project_with_no_composer_json_gets_no_pin(): void
    {
        $script = $this->script($this->build([], $this->phpDecision(), '', false));

        $this->assertStringContainsString('composer install --no-dev', $script);
        $this->assertStringNotContainsString('platform.php', $script);
    }

    /**
     * A committed lock does not, by itself, need a runtime manifest --
     * `install` reads the lock and resolves nothing, so a require-dev pin
     * cannot block it. But *this* project's minor still gets pinned (every
     * project with a composer.json does, {@see PhpHostBuild::platformPin()}),
     * and `composer config` writes wherever COMPOSER points. Left unset, that
     * is composer.json -- exactly the file ADR-0001 says the engine may never
     * write. So the pin redirects Composer to a runtime manifest here too,
     * carrying composer.json byte for byte, with composer.lock copied beside
     * it under the matching engine name so `install` still reads what the
     * project committed rather than resolving the whole graph remotely.
     */
    public function test_a_committed_lock_still_keeps_the_pin_out_of_composer_json(): void
    {
        $composerJson = (string) json_encode([
            'require' => ['php' => '^8.4', 'psr/log' => '^3'],
            'require-dev' => ['phpunit/phpunit' => '^9'],
        ]);
        $composerLock = '{"packages": [], "packages-dev": []}';
        $argv = $this->build([
            'composer.json' => $composerJson,
            'composer.lock' => $composerLock,
        ], $this->phpDecision());

        $this->assertNotNull($argv);
        $line = implode(' ', $argv);
        $this->assertStringContainsString(
            PhpHostBuild::MANIFEST_ENV . '=' . PhpHostBuild::RUNTIME_MANIFEST_FILE,
            $line,
            'a pin has to land somewhere other than composer.json'
        );
        $this->assertStringContainsString('composer install --no-dev', (string) end($argv));

        $manifestPath = '/home/acme/project/' . PhpHostBuild::RUNTIME_MANIFEST_FILE;
        $lockPath = '/home/acme/project/' . PhpHostBuild::RUNTIME_LOCK_FILE;
        $this->assertArrayHasKey($manifestPath, $this->copiedTo, 'the runtime manifest was never written');
        $this->assertSame($composerJson, $this->copiedTo[$manifestPath], 'composer.json must travel unchanged');
        $this->assertArrayHasKey($lockPath, $this->copiedTo, 'the lock was never copied beside the runtime manifest');
        $this->assertSame($composerLock, $this->copiedTo[$lockPath]);
    }

    /**
     * Composer's own file is never touched by any of this: it stays exactly
     * what the repository shipped, in the files map the build read from.
     */
    public function test_a_committed_lock_leaves_composer_json_byte_identical(): void
    {
        $composerJson = (string) json_encode(['require' => ['php' => '^8.4']]);
        $this->build([
            'composer.json' => $composerJson,
            'composer.lock' => '{"packages": []}',
        ], $this->phpDecision());

        $this->assertSame(
            $composerJson,
            $this->files['/home/acme/project/composer.json'],
            'nothing in this pass may write into the project\'s own composer.json'
        );
    }

    /**
     * A stale lock copy would let Composer install yesterday's dependencies
     * against a repository that has since pulled a new one. Refreshed here
     * means run every time, not written once and left behind.
     */
    public function test_the_lock_copy_is_refreshed_on_every_build(): void
    {
        $composerJson = (string) json_encode(['require' => ['php' => '^8.4']]);
        $lockPath = '/home/acme/project/' . PhpHostBuild::RUNTIME_LOCK_FILE;

        $this->build([
            'composer.json' => $composerJson,
            'composer.lock' => '{"packages": [], "revision": "first"}',
        ], $this->phpDecision());
        $this->assertSame('{"packages": [], "revision": "first"}', $this->copiedTo[$lockPath]);

        $this->files['/home/acme/project/composer.lock'] = '{"packages": [], "revision": "second"}';
        $this->build([], $this->phpDecision());
        $this->assertSame(
            '{"packages": [], "revision": "second"}',
            $this->copiedTo[$lockPath],
            'a new lock pulled from the repository must be the one Composer installs from'
        );
    }

    /**
     * The other half: without a lock `install` is impossible, the resolver
     * runs, and the require-dev that can block it is still dropped. This is
     * islandora's shape, and the reason the manifest exists.
     */
    public function test_without_a_lock_the_runtime_manifest_is_still_exported(): void
    {
        $argv = $this->build([
            'composer.json' => (string) json_encode([
                'require' => ['php' => '^8.4', 'psr/log' => '^3'],
                'require-dev' => ['phpunit/phpunit' => '^9'],
            ]),
        ], $this->phpDecision());

        $this->assertNotNull($argv);
        $this->assertStringContainsString(
            PhpHostBuild::MANIFEST_ENV . '=' . PhpHostBuild::RUNTIME_MANIFEST_FILE,
            implode(' ', $argv)
        );
    }

    /**
     * A manifest with no dependency step has nothing for a pin to precede, and
     * runPhpBuild() runs nothing at all -- the pin must not create a build that
     * did not exist.
     */
    public function test_an_empty_dependency_step_runs_nothing_at_all(): void
    {
        $this->assertNull($this->build(
            ['composer.json' => '{"require":{"php":"^8.4"}}'],
            ['install_command' => '', 'build_command' => ''],
            '',
            false
        ));
    }

    /**
     * Composer config inherits COMPOSER ({@see PhpHostBuild::MANIFEST_ENV}), so
     * a runtime manifest is what the pin is written into. A `--file` would
     * point it at the customer's own composer.json instead, which is the one
     * thing this path must never edit.
     */
    public function test_the_pin_is_written_through_composer_config_with_no_file_override(): void
    {
        $script = $this->script($this->build([
            'composer.json' => '{"require":{"php":"^8.4"}}',
        ], $this->phpDecision()));

        $this->assertStringNotContainsString('--file', $script);
        $this->assertStringNotContainsString('composer.json', $script);
    }

    /**
     * The install this pass produces is what the application runs on, so it
     * must keep checking the extension set the base image actually carries.
     * Extensions are ignored on the Composer-image pass because that image has
     * none of them; ignoring them here would skip a real check.
     */
    public function test_extensions_are_still_checked_in_the_runtime_image(): void
    {
        $script = $this->script($this->build([
            'composer.json' => '{"require":{"php":"^8.4"}}',
        ], $this->phpDecision()));

        $this->assertStringNotContainsString('--ignore-platform-reqs', $script);
        $this->assertStringNotContainsString('--ignore-platform-req', $script);
    }

    /** The deploy log's own milestone for the dependency step survives. */
    public function test_the_dependency_marker_is_preserved(): void
    {
        $script = $this->script($this->build([
            'composer.json' => '{"require":{"php":"^8.4"}}',
        ], $this->phpDecision()));

        $this->assertStringContainsString('echo "[panelalpha] build: dependencies" >&2', $script);
    }

    /**
     * A lock decides the PHP, so the build and the resolve finally agree.
     *
     * This is the egroupware case end to end: `require.php` admits 8.2 and a
     * 8.4 image is built, but the lock's `platform-overrides` is what
     * `composer install` obeys, so the pin has to say 8.2 -- pinning 8.4 was
     * the engine contradicting itself and the install answering with 22
     * unsolvable problems.
     */
    public function test_a_lock_decides_the_minor_the_install_is_pinned_to(): void
    {
        $script = $this->script($this->build([
            'composer.json' => (string) json_encode(['require' => ['php' => '>=8.2,<=8.5']]),
            'composer.lock' => (string) json_encode([
                'platform-overrides' => ['php' => '8.2'],
                'packages' => [['name' => 'ok/a', 'require' => ['php' => '^8.2']]],
            ]),
        ], $this->phpDecision()));

        $this->assertStringContainsString('composer config --no-plugins platform.php 8.2.99', $script);
        $this->assertStringNotContainsString('8.4.99', $script);
    }

    /**
     * ...and when the lock's own packages reject the PHP it pins, the install
     * is relaxed for php alone rather than failing a deploy that would work.
     *
     * The requirement to enforce is the one the lock itself states, so this is
     * not an escape from a real mismatch -- it is the engine declining to be
     * the one that fails on a lock that is merely out of date.
     */
    public function test_a_self_contradicting_lock_relaxes_php_only(): void
    {
        $script = $this->script($this->build([
            'composer.json' => (string) json_encode(['require' => ['php' => '>=8.2,<=8.5']]),
            'composer.lock' => (string) json_encode([
                'platform-overrides' => ['php' => '8.2'],
                'packages' => [['name' => 'needs-newer', 'require' => ['php' => '~8.4.0']]],
            ]),
        ], $this->phpDecision()));

        $this->assertStringContainsString('--ignore-platform-req=php', $script);
        $this->assertStringNotContainsString('--ignore-platform-reqs', $script);
        $this->assertStringContainsString(PhpHostBuild::lockContradictionNote(), $script);
    }

    /**
     * A locked platform the engine publishes no image for is not a minor to
     * pin -- but the install still applies it, so it is the contradiction to
     * relax.
     *
     * htmly's lock states 7.2, which no image here has ever been built from,
     * while composer.json admits `^8.1`. Pinning 7.2 would name a base image
     * that does not exist, so the constraint path still answers the image
     * (8.1); the install, though, verifies against the lock's 7.2 and dies on
     * `bacon/bacon-qr-code requires php ^8.1 ... your php version (7.2;
     * overridden via config.platform, actual: 8.1.34)`. Both facts come from
     * the same field and one of them is not a pin.
     */
    public function test_a_locked_platform_the_engine_does_not_publish_is_not_pinned_but_is_relaxed(): void
    {
        $script = $this->script($this->build([
            'composer.json' => (string) json_encode(['require' => ['php' => '^8.1']]),
            'composer.lock' => (string) json_encode([
                'platform-overrides' => ['php' => '7.2'],
                'packages' => [['name' => 'needs-newer', 'require' => ['php' => '^8.1']]],
            ]),
        ], $this->phpDecision()));

        // The image is resolved from the constraints, not from 7.2.
        $this->assertStringContainsString('platform.php 8.1.99', $script);
        $this->assertStringNotContainsString('platform.php 7.2', $script);
        // ...and the php requirement the lock's own packages reject is let go.
        $this->assertStringContainsString('--ignore-platform-req=php', $script);
    }
}
