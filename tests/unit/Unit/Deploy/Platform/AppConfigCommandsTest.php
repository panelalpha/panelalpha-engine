<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;

/**
 * An app config's staged commands, in the scripts the deploy actually runs.
 *
 * Until now they reached the two host stages and were dropped on the four
 * container ones, so a project could declare an install-stage command and
 * watch it never run. These assert the whole schedule.
 */
class AppConfigCommandsTest extends TestCase
{
    private function appConfig(string $commands, string $hooks = ''): AppConfig
    {
        $page = "- `" . AppConfig::YAML_FILENAME . "`\n```yaml\n"
            . "commands:\n" . $commands . "```\n" . $hooks;

        return AppConfig::fromMarkdown($page) ?? $this->fail('app config did not parse');
    }

    private function stageScript(AppConfig $appConfig): string
    {
        return StageScript::render(PlatformRegistry::find('laravel'), null, [], [], [], $appConfig);
    }

    /**
     * One phase's block of the entrypoint.
     *
     * Laravel stages `migrate` on both install and upgrade, so searching the
     * whole script for it finds the install copy and every ordering assertion
     * against it is meaningless.
     */
    private function phase(string $script, string $stage): string
    {
        $open = strpos($script, 'if [ "$PA_PHASE" = "' . $stage . '" ]; then');
        $this->assertNotFalse($open, "no {$stage} block in the generated script");
        $close = strpos($script, "\nfi", $open);

        return substr($script, $open, ($close === false ? strlen($script) : $close) - $open);
    }

    public function test_an_app_config_command_reaches_a_container_stage(): void
    {
        $script = $this->stageScript($this->appConfig(
            "  - {id: warm, stage: install, run: php artisan cache:warm}\n"
        ));

        $this->assertStringContainsString('php artisan cache:warm', $script);
    }

    public function test_before_runs_ahead_of_the_platforms_own(): void
    {
        $script = $this->stageScript($this->appConfig(
            "  - {id: preflight, stage: upgrade, before: true, run: ./bin/preflight.sh}\n"
        ));

        $upgrade = $this->phase($script, 'upgrade');

        $this->assertLessThan(
            strpos($upgrade, 'artisan migrate'),
            strpos($upgrade, './bin/preflight.sh'),
            'before: true must precede the platform migrate'
        );
    }

    public function test_without_before_an_app_config_command_follows_the_platforms(): void
    {
        $script = $this->stageScript($this->appConfig(
            "  - {id: after, stage: upgrade, run: ./bin/after.sh}\n"
        ));

        $upgrade = $this->phase($script, 'upgrade');

        $this->assertGreaterThan(strpos($upgrade, 'artisan migrate'), strpos($upgrade, './bin/after.sh'));
    }

    public function test_an_app_config_serve_command_replaces_the_platform_entrypoint(): void
    {
        $appConfig = $this->appConfig(
            "  - {id: serve, stage: start, serve: true, run: \"php -S 0.0.0.0:9000 -t public\"}\n"
        );

        $script = $this->stageScript($appConfig);

        $this->assertSame('serve', $appConfig->serveCommand()?->id);
        $this->assertStringContainsString('exec php -S 0.0.0.0:9000 -t public', $script);
        // One process, one port: the platform's own entrypoint is gone.
        $this->assertStringNotContainsString('apache2-foreground', $script);
    }

    /**
     * Cronicle's recipe (extends express) named its start `serve`, the id
     * express's own serve has, and the entrypoint ran package.json's `bin`
     * instead: the resolved project default is keyed by that id.
     */
    public function test_an_app_config_serve_sharing_the_platforms_id_is_not_overridden(): void
    {
        $appConfig = $this->appConfig(
            "  - {id: serve, stage: start, serve: true, run: \"node lib/main.js\"}\n"
        );

        $script = StageScript::render(
            PlatformRegistry::find('express'),
            null,
            ['serve' => 'node bin/control.sh'],
            [],
            [],
            $appConfig
        );

        $this->assertStringContainsString('exec node lib/main.js', $script);
        $this->assertStringNotContainsString('bin/control.sh', $script);
    }

    public function test_an_app_config_serve_placeholder_is_resolved_for_the_project(): void
    {
        $dir = sys_get_temp_dir() . '/pa-serve-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/package.json', '{"scripts":{"start":"node server.js"}}');
        file_put_contents($dir . '/yarn.lock', "# yarn lockfile v1\n");
        $appConfig = $this->appConfig(
            "  - {id: serve, stage: start, serve: true, run: \"{{js.start:node index.js}}\"}\n"
        );

        try {
            $script = StageScript::render(
                PlatformRegistry::find('express'),
                \App\Lib\Deploy\Platform\ProjectContext::at($dir),
                ['serve' => 'node bin/control.sh'],
                [],
                [],
                $appConfig
            );
        } finally {
            @unlink($dir . '/package.json');
            @unlink($dir . '/yarn.lock');
            @rmdir($dir);
        }

        $this->assertStringContainsString('exec yarn start', $script);
        $this->assertStringNotContainsString('{{js.', $script);
    }

    public function test_optional_and_timeout_survive_to_the_generated_script(): void
    {
        $script = $this->stageScript($this->appConfig(
            "  - {id: slow, stage: install, optional: true, timeout: 90, run: ./bin/slow.sh}\n"
        ));

        $this->assertStringContainsString("timeout --foreground 90", $script);
        $this->assertStringContainsString("|| pa_skip install 'slow'", $script);
    }

    public function test_the_precheck_hook_runs_after_the_staged_commands(): void
    {
        // panelalpha-before-clone-validation.sh has no structure to position
        // it by, so it stays where it has always been: last.
        $appConfig = $this->appConfig(
            "  - {id: quota, stage: precheck, run: ./bin/quota.sh}\n",
            "\n- `panelalpha-before-clone-validation.sh`\n```bash\ndf -h /\n```\n"
        );

        $script = HostScript::render(
            null,
            PlatformStage::PRECHECK,
            null,
            [],
            [AppConfig::PRE_CHECK_SCRIPT => (string) $appConfig->preCheckCommands()],
            $appConfig->commands(PlatformStage::PRECHECK)
        );

        $this->assertLessThan(strpos($script, 'df -h /'), strpos($script, './bin/quota.sh'));
    }

    public function test_the_after_clone_hook_still_runs_on_the_prepare_stage(): void
    {
        $appConfig = $this->appConfig(
            "  - {id: fetch, stage: prepare, before: true, run: ./bin/fetch.sh}\n",
            "\n- `panelalpha-after-clone.sh`\n```bash\ncp .env.example .env\n```\n"
        );

        $script = HostScript::render(
            PlatformRegistry::find('laravel'),
            PlatformStage::PREPARE,
            null,
            [],
            [AppConfig::SETUP_SCRIPT => (string) $appConfig->setupCommands()],
            $appConfig->commands(PlatformStage::PREPARE)
        );

        $this->assertStringContainsString('cp .env.example .env', $script);
        $this->assertLessThan(strpos($script, 'cp .env.example'), strpos($script, './bin/fetch.sh'));
    }
}
