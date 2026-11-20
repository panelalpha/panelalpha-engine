<?php

namespace Tests\Integration\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Integration\DeployHook\DeployHookTestCase;
use Tests\Integration\Git\FakesGitHost;

/**
 * The sweep on an account it may not settle now: another process holds its deploy lock or moved its checkout
 * aside, the account is gone, or its daemon cannot say what runs. Nothing is touched, and nothing is reported.
 */
class GenerationSweepBusyAccountTest extends DeployHookTestCase
{
    use FakesGitHost;

    /** @var resource|null */
    private $heldLock = null;

    private ?Process $otherProcess = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGitHost();
        @mkdir(DeployLogPaths::userDir('alice'), 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->heldLock)) {
            flock($this->heldLock, LOCK_UN);
            fclose($this->heldLock);
        }
        $this->otherProcess?->stop(0);
        @unlink(GenerationState::path('ghost'));
        DeployLogger::deleteUserLogs('alice');
        $this->restoreHost();
        parent::tearDown();
    }

    public function test_the_record_of_an_account_that_no_longer_exists_is_dropped(): void
    {
        (new GenerationState('ghost'))->put(GenerationState::ROUTES, ['containers' => ['app-1']]);
        $this->assertFileExists(GenerationState::path('ghost'));

        $this->assertSame([], GenerationSweep::run());
        $this->assertFileDoesNotExist(GenerationState::path('ghost'));
    }

    public function test_a_stranded_checkout_waits_while_another_process_holds_the_lock(): void
    {
        $runtime = $this->runtime();
        $aside = (new CheckoutAside($runtime))->asidePath();
        $this->respond('-name .project-prev', 0, $runtime->homeDirPath() . "\n");
        $this->respond('test -d ' . $runtime->userAppDirPath(), 1);
        $this->holdLockElsewhere();

        $this->assertSame([], GenerationSweep::run());
        $this->assertFalse($this->called('mv -T -- ' . $aside));
    }

    public function test_settling_when_idle_waits_for_a_lock_held_elsewhere(): void
    {
        $runtime = $this->runtime();
        (new GenerationState('alice'))->put(GenerationState::STOPPED, ['at' => time()]);
        $this->holdLockElsewhere();

        $this->assertNull(GenerationSweep::settleIfIdle($runtime, true));
        $this->assertNotNull((new GenerationState('alice'))->get(GenerationState::STOPPED));
    }

    public function test_an_interrupted_redeploy_waits_for_a_lock_held_elsewhere(): void
    {
        $runtime = $this->runtime();
        (new GenerationState('alice'))->put(GenerationState::ROUTES, ['containers' => ['app-1'], 'details' => ['app_port' => 3000]]);
        $this->holdLockElsewhere();

        $this->assertNull(GenerationSweep::settleInterrupted($runtime));
        $this->assertNotNull((new GenerationState('alice'))->get(GenerationState::ROUTES));
        $this->assertFalse($this->called('account-docker.containers'));
    }

    public function test_a_checkout_another_live_process_moved_aside_is_left_to_it(): void
    {
        $runtime = $this->runtime();
        $this->otherProcess = new Process(['sleep', '30']);
        $this->otherProcess->start();
        $pid = (int) $this->otherProcess->getPid();
        $state = new GenerationState('alice');
        $state->put(GenerationState::CHECKOUT, [
            'path' => (new CheckoutAside($runtime))->asidePath(),
            'containers' => ['app-1'],
            'owner' => ['pid' => $pid, 'start' => ProcessIdentity::startTime($pid), 'at' => time()],
        ]);

        $this->assertNull(GenerationSweep::settleInterrupted($runtime));
        $this->assertNotNull($state->get(GenerationState::CHECKOUT));
        $this->assertFalse($this->called('mv -T -- '));
    }

    public function test_an_interrupted_redeploy_is_left_while_its_daemon_cannot_say_what_runs(): void
    {
        $runtime = $this->runtime();
        (new GenerationState('alice'))->put(GenerationState::ROUTES, ['containers' => ['app-1'], 'details' => ['app_port' => 3000]]);
        $this->respond('account-docker.containers -- alice app-1', 69, "the Docker daemon of container alice is not running\n");

        $this->assertNull(GenerationSweep::settleInterrupted($runtime));
        $this->assertTrue($this->called('account-docker.containers -- alice app-1'));
        $this->assertNotNull((new GenerationState('alice'))->get(GenerationState::ROUTES));
    }

    public function test_a_second_generation_holding_no_rules_is_forgotten_without_moving_traffic(): void
    {
        $runtime = $this->runtime();
        $state = new GenerationState('alice');
        $state->put(GenerationState::NEXT, ['project' => 'project-next', 'routed' => 3000, 'routes' => [], 'rules' => []]);

        $this->assertTrue(GenerationSweep::settleNext($runtime));

        $this->assertNull($state->get(GenerationState::NEXT));
        $this->assertTrue($this->called($runtime->homeDirPath() . '/.panelalpha-next-generation.yml'));
    }

    public function test_a_second_generation_whose_override_cannot_be_removed_stays_recorded(): void
    {
        Log::spy();
        $runtime = $this->runtime();
        $state = new GenerationState('alice');
        $state->put(GenerationState::NEXT, ['project' => 'project-next', 'routed' => 3000, 'routes' => [], 'rules' => []]);
        $this->respond($runtime->homeDirPath() . '/.panelalpha-next-generation.yml', 1, "rm: cannot remove: Read-only file system\n");

        $this->assertFalse(GenerationSweep::settleNext($runtime));

        $this->assertNotNull($state->get(GenerationState::NEXT));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_starts_with($m, 'Could not settle the second generation of alice: '));
    }

    /** The account's deploy lock, taken through a handle of its own as another process would hold it. */
    private function holdLockElsewhere(): void
    {
        $this->heldLock = fopen((new DeployLogPaths('alice'))->lock(), 'c');
        $this->assertTrue(flock($this->heldLock, LOCK_EX | LOCK_NB));
    }

    private function runtime(): Dind
    {
        $runtime = $this->user()->project()->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
