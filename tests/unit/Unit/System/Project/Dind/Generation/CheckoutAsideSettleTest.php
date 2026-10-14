<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use Symfony\Component\Process\Process;
use Tests\Unit\DeployHook\DeployHookTestCase;
use Tests\Unit\Git\FakesGitHost;

/**
 * The sweep settling a checkout moved aside: the tree aside is never deleted
 * while ~/project is missing, and a dockerd that does not answer leaves
 * everything for a later sweep.
 */
class CheckoutAsideSettleTest extends DeployHookTestCase
{
    use FakesGitHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGitHost();
        @mkdir(DeployLogPaths::userDir('alice'), 0755, true);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs('alice');
        $this->restoreHost();
        parent::tearDown();
    }

    /** Moved aside by a process that died, the app stopped, nothing in ~/project: the sweep puts the tree back. */
    public function test_the_only_copy_of_the_checkout_is_put_back_not_removed(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: false));
        $state = new GenerationState('alice');
        $state->put(GenerationState::CHECKOUT, ['owner' => ['pid' => 999999, 'start' => null, 'at' => time()]] + $state->get(GenerationState::CHECKOUT));
        $this->respond('{{.State.Running}}', 0, "gone\n");
        $before = count($this->calls());

        $this->assertSame(['alice: checkout restored'], GenerationSweep::run());

        $after = array_slice($this->calls(), $before);
        $this->assertContains('mv -T ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath(), $after);
        $this->assertNotContains('rm -rf ' . $aside->asidePath(), $after);
    }

    /** A succeeded deploy that left no ~/project does not delete the tree aside either. */
    public function test_a_succeeded_deploy_without_a_checkout_keeps_the_tree_aside(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: false));

        $this->assertSame('restored', $aside->settle(true));
        $this->assertTrue($this->called('mv -T ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath()));
    }

    /**
     * A copy back that stopped partway leaves ~/project holding only a partial
     * tree: the sweep puts the recorded tree back and never deletes it.
     */
    public function test_a_half_copied_checkout_is_restored_not_removed(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $state = new GenerationState('alice');
        $state->put(GenerationState::CHECKOUT, [
            'path' => $aside->asidePath(),
            'containers' => ['app-1'],
            'binds' => true,
            'copy_back_pending' => true,
            'owner' => ['pid' => 999999, 'start' => null, 'at' => time()],
        ]);
        $this->respond('test -d ' . $aside->asidePath(), 0, '');
        $this->respond('{{.State.Running}}', 0, "false\n");

        $this->assertSame('restored', $aside->settle(false));

        $this->assertTrue($this->called('mv -T ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath()));
        $this->assertFalse($this->called('rm -rf ' . $aside->asidePath()));
    }

    /** A dockerd that cannot be asked is not "every container stopped": nothing is touched. */
    public function test_a_docker_that_does_not_answer_leaves_the_checkout_for_a_later_sweep(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: true));
        $this->respond('{{.State.Running}}', 1, "docker is not answering\n");
        $before = count($this->calls());

        $this->assertNull($aside->settle());

        $touched = array_filter(
            array_slice($this->calls(), $before),
            static fn (string $call): bool => str_starts_with($call, 'rm ') || str_starts_with($call, 'mv '),
        );
        $this->assertSame([], array_values($touched));
        $this->assertTrue($aside->restorable());
    }

    /** The inspect script itself: a daemon not up yet fails the question rather than answering "gone". */
    public function test_the_running_check_fails_while_dockerd_is_not_up(): void
    {
        $this->assertSame([1, ''], $this->runningCheck('exit 1'));
        $this->assertSame([0, "gone\n"], $this->runningCheck('[ "$1" = version ] && exit 0; exit 1'));
        $this->assertSame([0, "true\n"], $this->runningCheck('[ "$1" = version ] && exit 0; echo true'));
    }

    /** @return array{0: ?int, 1: string} the script's exit code and output with this `docker` on PATH */
    private function runningCheck(string $docker): array
    {
        $bin = sys_get_temp_dir() . '/pa-docker-' . bin2hex(random_bytes(4));
        mkdir($bin);
        file_put_contents("{$bin}/docker", "#!/bin/sh\n{$docker}\n");
        chmod("{$bin}/docker", 0755);
        try {
            $script = (new \ReflectionClassConstant(CheckoutAside::class, 'RUNNING_SCRIPT'))->getValue();
            $process = new Process(['bash', '-c', $script, 'running', 'app-1'], null, ['PATH' => $bin . ':/usr/bin:/bin']);
            $process->run();

            return [$process->getExitCode(), $process->getOutput()];
        } finally {
            unlink("{$bin}/docker");
            rmdir($bin);
        }
    }

    private function runtime(): Dind
    {
        $runtime = $this->user()->project()->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
