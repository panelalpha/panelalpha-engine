<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;

/**
 * The entrypoint the stage vocabulary compiles down to.
 *
 * These assert the behaviour the stages exist to produce, not the exact text
 * of the script — the point of the design is *when* a command runs.
 */
class StageScriptTest extends TestCase
{
    private function manifest(array $commands, string $id = 'demo'): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => $id,
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'port' => 8000,
            'detect' => ['file' => 'demo.json'],
            'commands' => $commands,
        ]);
    }

    /**
     * The defect the stage split exists to fix: a migration that used to sit
     * in the CMD string ran on every restart and every crash-loop retry.
     */
    public function test_a_migration_runs_on_install_and_upgrade_but_never_on_a_plain_start(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'migrate', 'stage' => ['install', 'upgrade'], 'run' => 'app migrate'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        // Present in both phase blocks...
        $this->assertSame(2, substr_count($script, 'app migrate'));
        $this->assertStringContainsString('if [ "$PA_PHASE" = "install" ]; then', $script);
        $this->assertStringContainsString('if [ "$PA_PHASE" = "upgrade" ]; then', $script);

        // ...and not on the unconditional path that every boot takes.
        $afterBlocks = substr($script, (int) strrpos($script, 'fi'));
        $this->assertStringNotContainsString('app migrate', $afterBlocks);
    }

    public function test_an_install_only_command_is_absent_from_the_upgrade_block(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'key-generate', 'stage' => 'install', 'run' => 'app key:generate'],
            ['id' => 'migrate', 'stage' => 'upgrade', 'run' => 'app migrate'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $install = substr($script, strpos($script, 'install" ]; then'), strpos($script, 'upgrade" ]; then') - strpos($script, 'install" ]; then'));
        $this->assertStringContainsString('app key:generate', $install);
        $this->assertStringNotContainsString('app migrate', $install);
    }

    /**
     * Without exec the shell stays PID 1 and swallows SIGTERM, so every deploy
     * waits out Docker's ten-second kill timer.
     */
    public function test_the_serve_command_is_execed_as_the_final_line(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'optimize', 'stage' => 'start', 'run' => 'app optimize'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $lines = array_values(array_filter(explode("\n", $script), static fn ($l) => trim($l) !== ''));
        $this->assertSame('exec app serve', end($lines));
    }

    public function test_an_optional_command_does_not_abort_the_boot(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'storage-link', 'stage' => 'install', 'run' => 'app storage:link', 'optional' => true],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $this->assertStringContainsString("app storage:link || pa_skip install 'storage-link'", $script);
    }

    public function test_set_e_aborts_the_boot_on_a_required_command(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'migrate', 'stage' => 'upgrade', 'run' => 'app migrate'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $this->assertStringContainsString('set -e', $script);
        $this->assertStringNotContainsString('app migrate ||', $script);
    }

    /**
     * Guessing "install" for an account that already holds data would re-run
     * seeders over live rows.
     */
    public function test_the_phase_defaults_to_upgrade_when_the_engine_did_not_say(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $this->assertStringContainsString('PA_PHASE="${PA_DEPLOY_PHASE:-upgrade}"', $script);
    }

    public function test_build_commands_split_into_a_cacheable_dependency_layer(): void
    {
        $manifest = $this->manifest([
            ['id' => 'deps', 'stage' => 'build', 'role' => 'dependencies', 'run' => 'install deps'],
            ['id' => 'bundle', 'stage' => 'build', 'run' => 'build assets'],
        ]);

        $layers = StageScript::buildLayers($manifest);

        $this->assertSame(['install deps'], $layers['dependencies']);
        $this->assertSame(['build assets'], $layers['assets']);
    }

    public function test_build_commands_never_reach_the_runtime_script(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'deps', 'stage' => 'build', 'role' => 'dependencies', 'run' => 'install deps'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $this->assertStringNotContainsString('install deps', $script);
    }

    public function test_a_timeout_wraps_the_command(): void
    {
        $script = StageScript::render($this->manifest([
            ['id' => 'migrate', 'stage' => 'upgrade', 'run' => 'app migrate', 'timeout' => 600],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]));

        $this->assertStringContainsString("timeout --foreground 600 sh -c 'app migrate'", $script);
    }

    /**
     * A `{{js.start:…}}` placeholder is the manifest speaking in generalities.
     * By render time the project has answered, and the entrypoint must carry
     * the answer, not the question.
     */
    public function test_a_resolved_command_overrides_the_manifest_default(): void
    {
        $script = StageScript::render(
            $this->manifest([
                ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => '{{js.start:npm start}}'],
            ]),
            null,
            ['serve' => 'npx next start -H 0.0.0.0 -p 3000']
        );

        $this->assertStringContainsString('exec npx next start -H 0.0.0.0 -p 3000', $script);
        $this->assertStringNotContainsString('{{js.start', $script);
    }

    /**
     * `exec if …` is a syntax error, and a serve command that picks between
     * two servers carries its own exec in each branch.
     */
    public function test_a_shell_construct_serve_command_is_not_prefixed_with_exec(): void
    {
        $script = StageScript::render($this->manifest([
            [
                'id' => 'serve',
                'stage' => 'start',
                'serve' => true,
                'run' => 'if [ -d /app/public ]; then exec php -S 0.0.0.0:8000 -t /app/public; else exec php -S 0.0.0.0:8000 -t /app; fi',
            ],
        ]));

        $this->assertStringNotContainsString('exec if ', $script);
        $this->assertStringContainsString("\nif [ -d /app/public ]", $script);
    }

    public function test_engine_supplied_commands_run_before_the_manifests_own(): void
    {
        $script = StageScript::render(
            $this->manifest([
                ['id' => 'migrate', 'stage' => ['install', 'upgrade'], 'run' => 'app migrate'],
                ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
            ]),
            null,
            [],
            [],
            [PlatformStage::UPGRADE => ['wait-for-db' => 'wait-for-it db:3306']]
        );

        $this->assertLessThan(
            strpos($script, "pa_step upgrade 'migrate'"),
            strpos($script, "pa_step upgrade 'wait-for-db'")
        );
    }

    public function test_the_shipped_laravel_manifest_produces_the_documented_lifecycle(): void
    {
        $laravel = PlatformRegistry::find('laravel');
        $this->assertNotNull($laravel);

        $ids = static fn (array $cs): array => array_map(static fn ($c) => $c->id, $cs);

        $this->assertSame(['composer-install', 'package-discover', 'asset-publish'], $ids($laravel->stage(PlatformStage::BUILD)));
        $this->assertSame(['key-generate', 'migrate'], $ids($laravel->stage(PlatformStage::INSTALL)));
        $this->assertSame(['migrate'], $ids($laravel->stage(PlatformStage::UPGRADE)));
        // No `serve` among the declared commands any more: a PHP manifest
        // says where its document root is and the image knows how to serve
        // it, so the serve command is supplied rather than written out.
        // storage-link on every start: a redeploy re-clones the checkout and
        // drops the public/storage symlink; install alone ran it once.
        $this->assertSame(['storage-link', 'optimize'], $ids($laravel->stage(PlatformStage::START)));
        $this->assertNotNull($laravel->serveCommand());
        $this->assertSame(PhpBaseImage::SERVE_PATH, $laravel->serveCommand()->run);
    }

    /**
     * The reason the default is a command rather than nothing:
     * {@see \App\System\Project\Dind\Strategy\EntrypointWriter}
     * writes no entrypoint at all for a manifest with no serve command, and a
     * Laravel account with no entrypoint gets no key:generate and no migrate
     * -- it would serve, and serve an unmigrated database.
     */
    public function test_every_php_manifest_still_ends_in_a_serve(): void
    {
        foreach (PlatformRegistry::all() as $manifest) {
            if ($manifest->runtime !== PlatformManifest::RUNTIME_PHP) {
                continue;
            }
            $serve = $manifest->serveCommand();
            $this->assertNotNull($serve, "{$manifest->id} has no serve command");
            $this->assertStringContainsString(
                $serve->run,
                StageScript::render($manifest),
                "{$manifest->id}'s entrypoint does not end in its serve command"
            );
        }
    }

    /**
     * Only PHP. Every other runtime's server is the application itself, and
     * there is nothing generic to hand over to -- a synthesized serve there
     * would run a script that does not exist in the image.
     */
    public function test_no_other_runtime_gets_a_default_serve(): void
    {
        foreach (PlatformRegistry::all() as $manifest) {
            if ($manifest->runtime === PlatformManifest::RUNTIME_PHP) {
                continue;
            }
            $serve = $manifest->serveCommand();
            if ($serve !== null) {
                $this->assertNotSame(PhpBaseImage::SERVE_PATH, $serve->run, $manifest->id);
            }
        }
    }
}
