<?php

namespace Tests\Integration\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use Illuminate\Support\Facades\Log;
use Tests\Integration\DeployHook\DeployHookTestCase;
use Tests\Integration\Git\FakesGitHost;

/**
 * Each host step of setting a checkout aside and settling it again, refused in turn: what is left recorded, what
 * is moved, and that the refusal is logged rather than thrown.
 */
class CheckoutAsideRefusedStepsTest extends DeployHookTestCase
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

    public function test_a_tree_that_cannot_be_moved_aside_leaves_no_record(): void
    {
        Log::spy();
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->respond('rm -rf -- ' . $aside->asidePath(), 1, "rm: cannot remove: Device or resource busy\n");

        $this->assertFalse($aside->moveAside(['app-1'], copyBack: true));

        $this->assertNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
        $this->assertFalse($this->called('mv -T -- ' . $runtime->userAppDirPath()));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_starts_with($m, 'Could not move the checkout of alice aside'));
    }

    public function test_a_copy_back_that_fails_puts_the_tree_back_and_forgets_it(): void
    {
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->respond('cp -a -T -- ' . $aside->asidePath(), 1, "cp: No space left on device\n");

        $this->assertFalse($aside->moveAside(['app-1'], copyBack: true));

        $this->assertTrue($this->called('mv -T -- ' . $runtime->userAppDirPath() . ' ' . $aside->asidePath()));
        $this->assertTrue($this->called('mv -T -- ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath()));
        $this->assertNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
    }

    public function test_a_tree_aside_that_cannot_be_removed_stays_recorded_for_the_sweep(): void
    {
        Log::spy();
        $runtime = $this->runtime();
        $aside = new CheckoutAside($runtime);
        $this->assertTrue($aside->moveAside(['app-1'], copyBack: true));
        $this->respond('rm -rf -- ' . $aside->asidePath(), 1, "rm: cannot remove: Permission denied\n");

        $this->assertNull($aside->settle(true));

        $this->assertNotNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_starts_with($m, 'Could not settle the checkout of alice'));
    }

    public function test_a_stranded_tree_that_cannot_be_put_back_is_left_where_it_is(): void
    {
        $runtime = $this->runtime();
        $aside = (new CheckoutAside($runtime))->asidePath();
        $this->respond('test -d ' . $aside, 0);
        $this->respond('test -d ' . $runtime->userAppDirPath(), 1);
        $this->respond('mv -T -- ' . $aside, 1, "mv: cannot move: Permission denied\n");

        $this->assertNull((new CheckoutAside($runtime))->reconcileStranded());
        $this->assertTrue($this->called('mv -T -- ' . $aside . ' ' . $runtime->userAppDirPath()));
    }

    public function test_a_checkout_that_cannot_be_listed_is_read_as_holding_files(): void
    {
        $runtime = $this->runtime();
        $aside = (new CheckoutAside($runtime))->asidePath();
        $this->respond('test -d ' . $aside, 0);
        $this->respond('ls -1A -- ' . $runtime->userAppDirPath(), 2, "ls: cannot open directory: Permission denied\n");

        $this->assertSame('not-restorable', (new CheckoutAside($runtime))->reconcileStranded());
        $this->assertFalse($this->called('mv -T -- ' . $aside));
    }

    public function test_homes_that_cannot_be_searched_name_no_account(): void
    {
        $runtime = $this->runtime();
        $this->respond('-name .project-prev', 1, "find: '/home': Permission denied\n");

        $this->assertSame([], CheckoutAside::accountsWithAside($runtime->system()));
    }

    public function test_a_running_app_mounting_the_checkout_keeps_its_tree_aside(): void
    {
        $runtime = $this->runtime(['deploy_strategy' => 'compose']);
        $aside = new CheckoutAside($runtime);
        $this->respondToStack($runtime->userAppDirPath() . '/data');

        $this->assertSame(['web mounts ./data'], $aside->keepForRunningApp());

        $entry = (new GenerationState('alice'))->get(GenerationState::CHECKOUT);
        $this->assertSame(['app-1'], $entry['containers']);
        $this->assertTrue($entry['binds']);
        $this->assertTrue($this->called('cp -a -T -- ' . $aside->asidePath() . ' ' . $runtime->userAppDirPath()));
        $this->assertNull($aside->keepForRunningApp(), 'moved aside once, by this process');
    }

    public function test_a_running_app_that_mounts_nothing_still_keeps_its_tree_aside(): void
    {
        $runtime = $this->runtime(['deploy_strategy' => 'compose']);
        $this->respondToStack('/srv/elsewhere');

        $this->assertSame([], (new CheckoutAside($runtime))->keepForRunningApp());
        $this->assertFalse((new GenerationState('alice'))->get(GenerationState::CHECKOUT)['binds']);
    }

    public function test_an_app_with_nothing_running_is_left_in_place(): void
    {
        $runtime = $this->runtime(['deploy_strategy' => 'compose']);
        $this->respond('account-docker.listContainers', 0, '[]');

        $this->assertNull((new CheckoutAside($runtime))->keepForRunningApp());
        $this->assertNull((new GenerationState('alice'))->get(GenerationState::CHECKOUT));
    }

    private function respondToStack(string $bindSource): void
    {
        $this->respond('account-docker.listContainers', 0, '[{"Id":"app-1","Names":"project-web-1"}]');
        $this->respond('account-docker.containers -- alice app-1', 0, json_encode([[
            'Id' => 'app-1',
            'State' => ['Running' => true],
            'Config' => ['Labels' => ['com.docker.compose.service' => 'web']],
            'Mounts' => [['Type' => 'bind', 'Source' => $bindSource, 'Destination' => '/data']],
        ]], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $details */
    private function runtime(array $details = []): Dind
    {
        $runtime = $this->user('main', $details)->project()->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
