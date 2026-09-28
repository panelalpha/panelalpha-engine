<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;

/**
 * Which Composer plugins the host install may run: the installers in
 * {@see PhpHostBuild::INSTALLER_PLUGINS}, and nothing else, whatever the
 * project says and whether or not it commits a lock.
 *
 * The rule used to be keyed on composer.lock and all-or-nothing, so a
 * lockless project was always installed `--no-plugins` (engine #168):
 * composer/installers never placed anything, and symfony/runtime's
 * vendor/autoload_runtime.php appeared only if the later `run-script` step
 * happened to load it. Composer 2.2's `allow-plugins` is enforced per plugin,
 * so the engine now writes its own into the runtime manifest and lets
 * Composer refuse the rest.
 */
class PhpHostBuildPluginTest extends TestCase
{
    /** @return array<string, mixed> */
    private function allowOf(?string $runtime): array
    {
        $this->assertNotNull($runtime);
        $decoded = json_decode((string) $runtime, true);

        return $decoded['config']['allow-plugins'];
    }

    /**
     * First match wins in Composer, so the catch-all must come last, and it
     * must be an explicit false: an unmentioned plugin throws
     * PluginBlockedException under --no-interaction.
     */
    public function test_the_allowlist_names_the_installers_then_refuses_everything_else(): void
    {
        $rules = PhpHostBuild::allowPlugins();

        $this->assertSame([...PhpHostBuild::INSTALLER_PLUGINS, '*'], array_keys($rules));
        foreach (PhpHostBuild::INSTALLER_PLUGINS as $plugin) {
            $this->assertTrue($rules[$plugin]);
        }
        $this->assertFalse($rules['*']);
    }

    /** The lockless case #168 is about: bolt/project's allow-plugins, abridged. */
    public function test_a_lockless_project_gets_the_engines_allow_plugins(): void
    {
        $composerJson = (string) json_encode([
            'require' => ['symfony/runtime' => '^6.4', 'symfony/flex' => '^2'],
            'config' => ['allow-plugins' => ['symfony/flex' => true, 'symfony/runtime' => true]],
        ]);

        $this->assertSame(PhpHostBuild::allowPlugins(), $this->allowOf(PhpHostBuild::runtimeManifest($composerJson)));
    }

    /**
     * The project's own allow-plugins is replaced, never merged: a blanket
     * `"*": true`, `allow-plugins: true`, or a named plugin cannot put
     * anything back.
     */
    public function test_the_projects_own_allow_plugins_cannot_widen_it(): void
    {
        foreach ([
            ['*' => true],
            ['acme/build-plugin' => true, '*' => true],
            true,
            [],
        ] as $theirs) {
            $composerJson = (string) json_encode([
                'require' => ['acme/build-plugin' => '^1'],
                'config' => ['allow-plugins' => $theirs, 'sort-packages' => true],
            ]);
            foreach ([null, '{"packages": []}'] as $lock) {
                $runtime = PhpHostBuild::runtimeManifest($composerJson, $lock);

                $this->assertSame(PhpHostBuild::allowPlugins(), $this->allowOf($runtime), (string) json_encode($theirs));
                // The rest of `config` is the project's.
                $this->assertTrue(json_decode((string) $runtime, true)['config']['sort-packages']);
            }
        }
    }

    /** A `config` that is not an object is replaced, not written into. */
    public function test_a_missing_or_malformed_config_still_gets_the_allowlist(): void
    {
        foreach (['{"require": {}}', '{"require": {}, "config": []}', '{"require": {}, "config": "x"}'] as $composerJson) {
            $this->assertSame(PhpHostBuild::allowPlugins(), $this->allowOf(PhpHostBuild::runtimeManifest($composerJson)));
        }
    }

    /**
     * Only `--no-plugins` goes, and only when the runtime manifest is what
     * Composer reads. Without it the flag stays: that is the fail-safe when
     * the manifest could not be written.
     */
    public function test_the_flag_is_dropped_only_under_the_runtime_manifest(): void
    {
        $declared = 'composer install --no-dev --no-interaction --no-scripts --no-plugins';

        $this->assertSame('composer install --no-dev --no-interaction --no-scripts', PhpHostBuild::installCommand($declared, true));
        $this->assertSame($declared, PhpHostBuild::installCommand($declared, false));
        $this->assertSame('', PhpHostBuild::installCommand('', true));
    }

    /**
     * The whole path. The install loses the flag; the platform pin keeps its
     * own, because `composer config` only writes a value and has no reason
     * to load a plugin. Scripts stay off either way.
     */
    public function test_the_script_runs_the_install_with_plugins_and_says_which(): void
    {
        $script = PhpHostBuild::script('', '', true, '8.2', false, true);
        $install = $this->installLine($script);

        $this->assertStringNotContainsString('--no-plugins', $install);
        $this->assertStringContainsString('--no-scripts', $install);
        $this->assertStringContainsString('composer config --no-plugins platform.php', $script);
        $this->assertStringContainsString('composer plugins allowed: composer/installers', $script);
    }

    public function test_the_script_keeps_the_flag_without_the_runtime_manifest(): void
    {
        $script = PhpHostBuild::script('', '', true, '8.2', false, false);

        $this->assertStringContainsString('--no-plugins', $this->installLine($script));
        $this->assertStringNotContainsString('composer plugins allowed', $script);
    }

    /**
     * The allowlist covers the install only. The asset half gets the
     * project's own allow-plugins back: php.yaml's `run-script
     * post-autoload-dump` is where bolt/project gets drupol/composer-packages,
     * and taking that away is a separate decision.
     */
    public function test_the_asset_half_gets_the_projects_allow_plugins_back(): void
    {
        $build = '{ composer run-script --no-interaction post-autoload-dump; } || true';

        $lines = explode("\n", PhpHostBuild::script('', $build, true, '8.2', false, true));
        $restore = array_search(PhpHostBuild::restoreProjectAllowPlugins(), $lines, true);
        $this->assertIsInt($restore);
        $this->assertGreaterThan(array_search($this->installLine(implode("\n", $lines)), $lines, true), $restore);
        $this->assertSame($build, $lines[$restore + 1]);

        $this->assertStringNotContainsString('php -r', PhpHostBuild::script('', $build, true, '8.2', false, false));
    }

    /** The restore step itself, run for real against a written manifest. */
    public function test_the_restore_step_copies_allow_plugins_from_composer_json(): void
    {
        $dir = sys_get_temp_dir() . '/pa-restore-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach ([
                [['symfony/flex' => true, 'x/*' => false], ['symfony/flex' => true, 'x/*' => false]],
                [true, true],
                [null, null],
            ] as [$theirs, $expected]) {
                $project = ['require' => ['a/b' => '^1'], 'config' => ['sort-packages' => true]];
                if ($theirs !== null) {
                    $project['config']['allow-plugins'] = $theirs;
                }
                file_put_contents($dir . '/composer.json', (string) json_encode($project));
                file_put_contents(
                    $dir . '/' . PhpHostBuild::RUNTIME_MANIFEST_FILE,
                    (string) PhpHostBuild::runtimeManifest((string) json_encode($project))
                );

                $process = \Symfony\Component\Process\Process::fromShellCommandline(
                    PhpHostBuild::restoreProjectAllowPlugins(),
                    $dir,
                    [PhpHostBuild::MANIFEST_ENV => PhpHostBuild::RUNTIME_MANIFEST_FILE]
                );
                $process->mustRun();

                $written = json_decode((string) file_get_contents($dir . '/' . PhpHostBuild::RUNTIME_MANIFEST_FILE), true);
                $this->assertSame($expected, $written['config']['allow-plugins'] ?? null);
                $this->assertTrue($written['config']['sort-packages']);
            }
        } finally {
            array_map('unlink', glob($dir . '/{,.}*.json', GLOB_BRACE) ?: []);
            rmdir($dir);
        }
    }

    /**
     * contao's container runs `composer install` against the lock the host
     * wrote. A lockless project with no require-dev used to resolve from
     * composer.json, so that lock was composer.lock; under the runtime
     * manifest it is the engine's name, and is copied back.
     */
    public function test_a_lockless_build_with_nothing_dropped_still_leaves_composer_lock(): void
    {
        $this->assertTrue(PhpHostBuild::mirrorsRuntimeLock('{"require": {"psr/log": "^3"}}', null));
        $this->assertTrue(PhpHostBuild::mirrorsRuntimeLock('{"require-dev": {"a/b": "^1"}}', ''));
        // require-dev dropped: that lock was never composer.lock.
        $this->assertFalse(PhpHostBuild::mirrorsRuntimeLock('{"require": {}, "require-dev": {"a/b": "^1"}}', null));
        // The project has its own lock.
        $this->assertFalse(PhpHostBuild::mirrorsRuntimeLock('{"require": {}}', '{"packages": []}'));
        $this->assertFalse(PhpHostBuild::mirrorsRuntimeLock('not json', null));

        $lines = explode("\n", PhpHostBuild::script('', '', true, '8.2', false, true, true));
        $this->assertSame(
            '[ -e composer.lock ] || cp ' . PhpHostBuild::RUNTIME_LOCK_FILE . ' composer.lock',
            end($lines)
        );
        $this->assertStringNotContainsString(' cp ', PhpHostBuild::script('', '', true, '8.2', false, true, false));
    }

    /** The install command in a script, not the pin above it. */
    private function installLine(string $script): string
    {
        foreach (explode("\n", $script) as $line) {
            if (str_contains($line, 'composer install')) {
                return $line;
            }
        }

        return '';
    }
}
