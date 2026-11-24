<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFinishLine;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStartLine;
use Tests\TestCase;

/**
 * A create without git leaves its first deploy `waiting` for project files. The deploy that brings them opens one
 * of its own, and the waiting one is closed as taken over, never as a deploy whose process died.
 */
class DeployLoggerWaitingForFilesTest extends TestCase
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

    private function waitingForFiles(): string
    {
        $username = 'waiting-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;
        $logger = DeployLogger::start($username);
        $logger->info(DeployStartLine::text('dind template'));
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $logger->waitForFiles();
        unset($logger);
        gc_collect_cycles();

        return $username;
    }

    public function test_the_waiting_deploy_is_neither_running_nor_finished(): void
    {
        $latest = DeployLogger::readLatestFor($this->waitingForFiles());

        $this->assertSame(DeployLogger::STATUS_WAITING, $latest['status']);
        $this->assertSame(DeployLogger::STAGE_PREPARING, $latest['stage']);
        $this->assertNotNull($latest['stages'][0]['finished_at'], 'preparing is over');
        $this->assertNull($latest['finished_at']);
        $this->assertNull($latest['pid']);
    }

    /** A typed create that brings an archive continues under the log that waited for it. */
    public function test_files_the_create_brings_itself_make_the_waiting_deploy_run_again(): void
    {
        $username = 'waiting-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $logger->waitForFiles();

        $logger->filesArrived();

        $this->assertSame(DeployLogger::STATUS_RUNNING, $logger->readLatest()['status']);
        $logger->stage(DeployLogger::STAGE_CLONING);
        $logger->finish(DeployLogger::STATUS_SUCCESS);
        $this->assertSame(DeployLogger::STATUS_SUCCESS, DeployLogger::readLatestFor($username)['status']);
    }

    public function test_the_deploy_that_brings_the_files_opens_its_own_and_closes_the_waiting_one(): void
    {
        $username = $this->waitingForFiles();
        $waiting = DeployLogger::readLatestFor($username)['id'];

        $deploy = DeployLogger::resumeRunningOrStart($username);

        $this->assertNotSame($waiting, $deploy->getDeployId());
        $this->assertSame($deploy->getDeployId(), DeployLogger::readLatestFor($username)['id'], 'latest.json is the deploy that started');
        $this->assertSame(DeployLogger::STATUS_RUNNING, $deploy->readLatest()['status']);
        $closed = DeployFinishLine::find(DeployLogger::forDeploy($username, $waiting)->tail(DeployFinishLine::LOOKBACK));
        $this->assertSame(DeployLogger::STATUS_CANCELLED, $closed['status'] ?? null);
        $this->assertSame(DeployLogger::WAITING_TAKEN_OVER, $closed['error'] ?? null);
        $this->assertSame(DeployStartLine::KIND_INSTALL, DeployStartLine::read(DeployLogger::forDeploy($username, $waiting)->entries())['kind'], 'the waiting deploy stays the install');
        $deploy->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_the_waiting_deploy_is_never_closed_as_one_whose_process_is_gone(): void
    {
        $username = $this->waitingForFiles();
        $waiting = DeployLogger::readLatestFor($username)['id'];

        DeployLogger::resumeRunningOrStart($username)->finish(DeployLogger::STATUS_SUCCESS);
        $closed = DeployFinishLine::find(DeployLogger::forDeploy($username, $waiting)->tail(DeployFinishLine::LOOKBACK));

        $this->assertNotSame(DeployLogger::STATUS_FAILED, $closed['status'] ?? null);
        $this->assertStringNotContainsString('engine process', (string) ($closed['error'] ?? ''));
    }

    public function test_a_deploy_still_running_keeps_its_id(): void
    {
        $username = $this->waitingForFiles();
        $running = DeployLogger::resumeRunningOrStart($username);
        unset($running);
        gc_collect_cycles();
        $id = DeployLogger::readLatestFor($username)['id'];

        $resumed = DeployLogger::resumeRunningOrStart($username);

        $this->assertSame($id, $resumed->getDeployId(), 'a deploy that is not waiting for files is continued');
        $resumed->finish(DeployLogger::STATUS_SUCCESS);
    }
}
