<?php

namespace Tests\Unit\Deploy\Platform;

use App\System\Project\Dind\DeployStrategy;
use PHPUnit\Framework\TestCase;

/**
 * The prepare stage has to be reachable from every strategy.
 *
 * It was not. When project app configs gained staged commands, the prepare stage
 * was only ever run from the compose branch, so a `panelalpha.yaml` declaring
 * `stage: prepare` on a Laravel, Ruby, Go or Next project parsed fine,
 * validated fine, and silently never ran. Nothing failed; the commands simply
 * did not happen.
 *
 * A source-level check because the alternative is standing up a full DinD
 * account. What it guards is cheap to state and easy to break again: the
 * dispatcher itself must run the stage, not only one of the branches it
 * dispatches to. The body is located by reflection, so moving the dispatcher
 * breaks this on a missing method rather than on a filename.
 */
class PrepareStageReachabilityTest extends TestCase
{
    private function applyMethodBody(): string
    {
        $reflected = new \ReflectionMethod(DeployStrategy::class, 'apply');
        $file = (string) $reflected->getFileName();
        $this->assertFileIsReadable($file);

        $lines = (array) file($file);
        $start = $reflected->getStartLine() - 1;

        return implode('', array_slice($lines, $start, $reflected->getEndLine() - $start));
    }

    public function test_apply_runs_the_prepare_stage_itself(): void
    {
        $this->assertStringContainsString(
            'prepare()->run(',
            $this->applyMethodBody(),
            'apply() must run the prepare stage, or app configs that declare '
            . 'prepare commands only get them on the compose path'
        );
    }

    /**
     * The compose path runs the platform's prepare at its own point — only
     * when there is no .env yet — so apply() must not run it twice for that
     * branch.
     */
    public function test_the_compose_branch_returns_before_the_shared_call(): void
    {
        $body = $this->applyMethodBody();

        $composeAt = strpos($body, 'userCompose()->apply(');
        $prepareAt = strpos($body, 'prepare()->run(');

        $this->assertIsInt($composeAt, 'the compose branch was not found in apply()');
        $this->assertIsInt($prepareAt, 'the shared prepare call was not found in apply()');
        $this->assertLessThan(
            $prepareAt,
            $composeAt,
            'the compose branch must return before apply() runs prepare itself'
        );
        $this->assertStringContainsString(
            'return;',
            substr($body, $composeAt, $prepareAt - $composeAt),
            'the compose branch must return rather than fall through'
        );
    }

    /**
     * The compose branch does still run it, just at its own point and under
     * its own condition — that is the whole reason it returns early above.
     */
    public function test_the_compose_branch_runs_the_prepare_stage_at_its_own_point(): void
    {
        $reflected = new \ReflectionMethod(
            \App\System\Project\Dind\Strategy\UserComposeStrategy::class,
            'apply'
        );
        $lines = (array) file((string) $reflected->getFileName());
        $start = $reflected->getStartLine() - 1;
        $body = implode('', array_slice($lines, $start, $reflected->getEndLine() - $start));

        $this->assertStringContainsString('->run(', $body);
        $this->assertStringContainsString('.env', $body, 'gated on the project having no .env yet');
    }
}
