<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Which Composer plugins the host install may run: the installers in
 * {@see PhpHostBuild::INSTALLER_PLUGINS}, and nothing else, whatever the
 * project says and whether or not it commits a lock.
 *
 * The rule used to be keyed on composer.lock and all-or-nothing, so a
 * lockless project was always installed `--no-plugins`:
 * composer/installers never placed anything, and symfony/runtime's
 * vendor/autoload_runtime.php appeared only if the later `run-script` step
 * happened to load it. Composer 2.2's `allow-plugins` is enforced per plugin,
 * so the engine now writes its own into the runtime manifest and lets
 * Composer refuse the rest.
 */
class PhpHostBuildPluginTest extends TestCase
{
    /** The restore step itself, run for real against a written manifest. */
    public function test_the_restore_step_copies_allow_plugins_from_composer_json(): void
    {
        $dir = sys_get_temp_dir().'/pa-restore-'.bin2hex(random_bytes(4));
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
                file_put_contents($dir.'/composer.json', (string) json_encode($project));
                file_put_contents(
                    $dir.'/'.PhpHostBuild::RUNTIME_MANIFEST_FILE,
                    (string) PhpHostBuild::runtimeManifest((string) json_encode($project))
                );

                $process = Process::fromShellCommandline(
                    PhpHostBuild::restoreProjectAllowPlugins(),
                    $dir,
                    [PhpHostBuild::MANIFEST_ENV => PhpHostBuild::RUNTIME_MANIFEST_FILE]
                );
                $process->mustRun();

                $written = json_decode((string) file_get_contents($dir.'/'.PhpHostBuild::RUNTIME_MANIFEST_FILE), true);
                $this->assertSame($expected, $written['config']['allow-plugins'] ?? null);
                $this->assertTrue($written['config']['sort-packages']);
            }
        } finally {
            array_map('unlink', glob($dir.'/{,.}*.json', GLOB_BRACE) ?: []);
            rmdir($dir);
        }
    }

    /** First match wins in allow-plugins, so the merge plugin goes ahead of the catch-all. */
    public function test_the_merge_step_allows_the_merge_plugin_ahead_of_the_catch_all(): void
    {
        $dir = sys_get_temp_dir().'/pa-merge-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $project = ['require' => [PhpHostBuild::MERGE_PLUGIN => '^2.1'], 'config' => ['sort-packages' => true]];
            file_put_contents(
                $dir.'/'.PhpHostBuild::RUNTIME_MANIFEST_FILE,
                (string) PhpHostBuild::runtimeManifest((string) json_encode($project))
            );

            Process::fromShellCommandline(
                PhpHostBuild::allowMergePlugin(),
                $dir,
                [PhpHostBuild::MANIFEST_ENV => PhpHostBuild::RUNTIME_MANIFEST_FILE]
            )->mustRun();

            $written = json_decode((string) file_get_contents($dir.'/'.PhpHostBuild::RUNTIME_MANIFEST_FILE), true);
            $this->assertSame(
                [PhpHostBuild::MERGE_PLUGIN => true, ...PhpHostBuild::allowPlugins()],
                $written['config']['allow-plugins']
            );
            $this->assertTrue($written['config']['sort-packages']);
        } finally {
            array_map('unlink', glob($dir.'/{,.}*.json', GLOB_BRACE) ?: []);
            rmdir($dir);
        }
    }
}
