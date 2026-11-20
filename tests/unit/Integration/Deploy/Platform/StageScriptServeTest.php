<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;

/**
 * The entrypoint's last lines: which serve command wins, and what a replaced start stage without one does.
 */
class StageScriptServeTest extends TestCase
{
    private function manifest(): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'detect' => ['file' => 'demo.json'],
            'commands' => [
                ['id' => 'warm', 'stage' => 'start', 'run' => 'app warm'],
                ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
            ],
        ]);
    }

    private function appConfig(string $run): AppConfig
    {
        $page = "- `" . AppConfig::YAML_FILENAME . "`\n```yaml\ncommands:\n"
            . "  - {id: own, stage: start, serve: true, run: \"{$run}\"}\n```\n";

        return AppConfig::fromMarkdown($page) ?? $this->fail('app config did not parse');
    }

    /** @return list<string> */
    private function tail(string $script, int $lines): array
    {
        return array_slice(explode("\n", rtrim($script, "\n")), -$lines);
    }

    public function test_a_replaced_start_stage_without_serve_exits_loudly(): void
    {
        $script = StageScript::render($this->manifest(), null, [], [], [], null, DeployPlan::fromArray(['start' => [['id' => 'only', 'run' => 'app only']]]));

        $this->assertSame([
            "pa_step start 'no serve command'",
            "printf '[panelalpha] the deploy request replaced the start stage without a serve command; there is nothing to run\\n' >&2",
            'exit 1',
        ], $this->tail($script, 3));
        $this->assertStringContainsString("pa_step start 'replaced by the deploy request'", $script);
        $this->assertStringNotContainsString('app warm', $script);
    }

    public function test_a_replaced_start_stage_serves_the_plans_command_without_overrides(): void
    {
        $script = StageScript::render(
            $this->manifest(),
            null,
            ['serve' => 'overridden serve'],
            [],
            [],
            $this->appConfig('own serve'),
            DeployPlan::fromArray(['start' => [['id' => 'serve', 'run' => 'plan serve', 'serve' => true]]])
        );

        $this->assertSame(["pa_step start 'serve'", 'exec plan serve'], $this->tail($script, 2));
    }

    public function test_an_app_configs_serve_ignores_the_platforms_overrides(): void
    {
        $script = StageScript::render($this->manifest(), null, ['serve' => 'overridden serve', 'own' => 'overridden own'], [], [], $this->appConfig('own serve'));

        $this->assertSame(["pa_step start 'own'", 'exec own serve'], $this->tail($script, 2));
    }

    public function test_an_app_configs_serve_placeholder_resolves_against_the_context(): void
    {
        $dir = sys_get_temp_dir() . '/pa-stage-serve-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/package.json', '{"scripts":{"start":"node server.js"}}');

        try {
            $config = $this->appConfig('{{js.start:node index.js}}');
            $withContext = StageScript::render($this->manifest(), ProjectContext::at($dir), [], [], [], $config);
            $withoutContext = StageScript::render($this->manifest(), null, [], [], [], $config);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame(["pa_step start 'own'", 'exec npm start'], $this->tail($withContext, 2));
        $this->assertSame(["pa_step start 'own'", 'exec {{js.start:node index.js}}'], $this->tail($withoutContext, 2));
    }

    public function test_the_platforms_serve_takes_the_overrides(): void
    {
        $script = StageScript::render($this->manifest(), null, ['serve' => 'overridden serve']);

        $this->assertSame(["pa_step start 'serve'", 'exec overridden serve'], $this->tail($script, 2));
        $this->assertStringContainsString('app warm', $script);
    }
}
