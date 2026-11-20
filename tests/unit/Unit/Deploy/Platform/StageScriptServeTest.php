<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;
use Tests\Support\StageScriptServeFixtures;

/**
 * The entrypoint's last lines: which serve command wins, and what a replaced start stage without one does.
 */
class StageScriptServeTest extends TestCase
{
    use StageScriptServeFixtures;

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

    public function test_the_platforms_serve_takes_the_overrides(): void
    {
        $script = StageScript::render($this->manifest(), null, ['serve' => 'overridden serve']);

        $this->assertSame(["pa_step start 'serve'", 'exec overridden serve'], $this->tail($script, 2));
        $this->assertStringContainsString('app warm', $script);
    }
}
