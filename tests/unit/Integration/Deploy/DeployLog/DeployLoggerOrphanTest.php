<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFinishLine;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStartLine;
use App\System\Project\Dind\InnerBuildGuard;
use Tests\TestCase;

/**
 * A deploy whose process died before finish() wrote its status: a queue
 * worker lost to a core restart (warpgate), a synchronous archive deploy
 * killed by a host OOM after it had logged "Deploy failed" (komga). Both
 * read `running` for good, and a client polling for a terminal status
 * waited forever.
 *
 * Dropping the logger stands in for the dead process: its destructor
 * releases the deploy lock, as the kernel does for a killed one.
 */
class DeployLoggerOrphanTest extends TestCase
{
    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        gc_collect_cycles();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function username(): string
    {
        $username = 'orphan-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }

    /** A deploy that got to the build stage and whose process then vanished. */
    private function diedMidBuild(): string
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $logger->dim('#11 [base 7/7] RUN bundle install');
        unset($logger);
        gc_collect_cycles();

        return $username;
    }

    public function test_a_deploy_whose_process_is_gone_is_closed_as_failed(): void
    {
        $username = $this->diedMidBuild();

        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::settleOrphaned($username));

        $latest = DeployLogger::readLatestFor($username);
        $this->assertSame(DeployLogger::STATUS_FAILED, $latest['status']);
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $latest['error']);
        $this->assertNotNull($latest['finished_at']);
        $this->assertNotNull($latest['stages'][0]['finished_at'], 'the open stage is closed');
        $last = DeployLogger::current($username)->tail(1)[0];
        $this->assertSame('Deploy failed: ' . DeployLogger::INTERRUPTED_MESSAGE, $last['msg']);
        // Closed once: a second sweep finds nothing to do.
        $this->assertNull(DeployLogger::settleOrphaned($username));
    }

    public function test_a_deploy_killed_after_its_finish_line_keeps_that_verdict(): void
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        // finish() writes the line, then reports to telemetry, then the status.
        (new \ReflectionMethod(DeployLogger::class, 'writeLine'))->invoke(
            $logger,
            DeployLogger::LEVEL_ERROR,
            'Deploy failed: The build ran out of memory.',
            DeployLogger::STAGE_RUNNING
        );
        unset($logger);
        gc_collect_cycles();

        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::settleOrphaned($username));

        $latest = DeployLogger::readLatestFor($username);
        $this->assertSame('The build ran out of memory.', $latest['error']);
        $lines = array_column(DeployLogger::current($username)->read()['lines'], 'msg');
        $this->assertSame(1, count(preg_grep('/^Deploy failed/', $lines)), 'no second finish line');
    }

    public function test_a_success_line_is_adopted_as_success(): void
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        (new \ReflectionMethod(DeployLogger::class, 'writeLine'))->invoke($logger, DeployLogger::LEVEL_OK, 'Deploy finished successfully', null);
        unset($logger);
        gc_collect_cycles();

        $this->assertSame(DeployLogger::STATUS_SUCCESS, DeployLogger::settleOrphaned($username));
        $this->assertNull(DeployLogger::readLatestFor($username)['error']);
    }

    public function test_a_deploy_still_running_is_left_alone(): void
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $this->assertNull(DeployLogger::settleOrphaned($username));
        $this->assertNull(DeployLogger::settleOrphaned($username, true));
        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status']);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    /** POST /users for DinD without git: `waiting`, no lock, until files arrive. */
    public function test_an_account_waiting_for_its_files_is_left_alone(): void
    {
        $username = $this->waitingForFiles();

        $this->assertNull(DeployLogger::settleOrphaned($username, true));
        $this->assertNull(DeployLogger::settleOrphaned($username));
        $this->assertNotContains($username, DeployLogger::settleOrphanedDeploys());
        $this->assertSame(DeployLogger::STATUS_WAITING, DeployLogger::readLatestFor($username)['status']);
    }

    /** `up` on the waiting account announces the image build's memory, and outbound limits warn, in the same log. */
    public function test_an_account_waiting_for_its_files_stays_waiting_after_lines_written_on_it(): void
    {
        $username = $this->waitingForFiles();
        $later = DeployLogger::current($username);
        $later->info(InnerBuildGuard::announcement(8192));
        $later->warn('Outbound limits reached');
        $later->writeProcessBuffer('out', "0\norigin/main\n");
        unset($later);
        gc_collect_cycles();

        $this->assertNull(DeployLogger::settleOrphaned($username, true));
        $this->assertNull(DeployLogger::settleOrphaned($username));
        $this->assertNotContains($username, DeployLogger::settleOrphanedDeploys());
        $latest = DeployLogger::readLatestFor($username);
        $this->assertSame(DeployLogger::STATUS_WAITING, $latest['status']);
        $this->assertNull($latest['failure'] ?? null);
        $this->assertNull(DeployFinishLine::find(DeployLogger::current($username)->tail(DeployFinishLine::LOOKBACK)));
    }

    /** A create that stopped on its waiting line before `waiting` was recorded is marked waiting, not closed as failed. */
    public function test_a_deploy_left_running_on_its_waiting_line_is_marked_waiting(): void
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $logger->info(DeployLogger::WAITING_FOR_FILES);
        unset($logger);
        gc_collect_cycles();

        $this->assertNull(DeployLogger::settleOrphaned($username, true));
        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status'], 'a dry run changes nothing');

        $this->assertNull(DeployLogger::settleOrphaned($username));
        $latest = DeployLogger::readLatestFor($username);
        $this->assertSame(DeployLogger::STATUS_WAITING, $latest['status']);
        $this->assertNotNull($latest['stages'][0]['finished_at']);
        $this->assertNull($latest['finished_at']);
    }

    /** The deploy that brought the waiting project its files and then died: that one is an orphan. */
    public function test_a_deploy_that_took_a_waiting_project_over_and_died_is_closed(): void
    {
        $username = $this->waitingForFiles();
        $deploy = DeployLogger::resumeRunningOrStart($username);
        $deploy->info(DeployStartLine::text('archive'));
        $deploy->dim('Recreating home and project directories');
        unset($deploy);
        gc_collect_cycles();

        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::settleOrphaned($username));
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, DeployLogger::readLatestFor($username)['error']);
    }

    private function waitingForFiles(): string
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $logger->waitForFiles();
        unset($logger);
        gc_collect_cycles();

        return $username;
    }

    public function test_a_dry_run_reports_without_closing(): void
    {
        $username = $this->diedMidBuild();

        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::settleOrphaned($username, true));
        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($username)['status']);
    }

    public function test_the_sweep_closes_only_orphans(): void
    {
        $orphan = $this->diedMidBuild();
        $live = $this->username();
        $logger = DeployLogger::start($live);
        $done = $this->username();
        DeployLogger::start($done)->finish(DeployLogger::STATUS_SUCCESS);

        $settled = DeployLogger::settleOrphanedDeploys();

        $this->assertContains($orphan, $settled);
        $this->assertNotContains($live, $settled);
        $this->assertNotContains($done, $settled);
        $this->assertSame(DeployLogger::STATUS_RUNNING, DeployLogger::readLatestFor($live)['status']);
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }
}
