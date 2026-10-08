<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Tests\TestCase;

/**
 * The deploy log an account's commands write to, and are stopped by. Every
 * command throws while the log reads `cancelled`; it read that for good once
 * the cancelled deploy had finished, so git on the account failed until the
 * next deploy rewrote it.
 */
class ShellLoggerAfterCancelTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'shlog-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    private function shell(): ShellOperations
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);

        return new ShellOperations($dind);
    }

    public function test_a_running_deploy_takes_the_commands_output(): void
    {
        $logger = DeployLogger::start($this->username);

        $this->assertSame($logger->getDeployId(), $this->shell()->logger()?->getDeployId());

        $logger->finish(DeployLogger::STATUS_SUCCESS);
        $this->assertNull($this->shell()->logger());
    }

    public function test_a_cancel_stops_commands_only_until_the_deploy_has_finished(): void
    {
        $logger = DeployLogger::start($this->username);
        DeployLogger::requestCancel($this->username);

        $this->assertSame($logger->getDeployId(), $this->shell()->logger()?->getDeployId(), 'winding down: each command throws');

        $logger->finish(DeployLogger::STATUS_CANCELLED, 'Deployment cancelled by user');
        $this->assertNull($this->shell()->logger(), 'finished: commands run again');
    }

    public function test_a_cancelled_deploy_whose_process_is_gone_stops_nothing(): void
    {
        $logger = DeployLogger::start($this->username);
        DeployLogger::requestCancel($this->username);
        // The kernel drops a dead process's lock; nothing will ever finish this log.
        unset($logger);
        gc_collect_cycles();

        $this->assertSame(DeployLogger::STATUS_CANCELLED, DeployLogger::readLatestFor($this->username)['status']);
        $this->assertNull($this->shell()->logger());
    }
}
