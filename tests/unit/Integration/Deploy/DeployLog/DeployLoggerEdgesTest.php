<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Tests\TestCase;

/** A line that sanitises to nothing, and a preparing deploy whose log is gone when the sweep finds it. */
class DeployLoggerEdgesTest extends TestCase
{
    private string $username = '';

    protected function tearDown(): void
    {
        gc_collect_cycles();
        if ($this->username !== '') {
            DeployLogger::deleteUserLogs($this->username);
        }
        parent::tearDown();
    }

    public function test_a_message_that_is_only_whitespace_and_escapes_writes_no_line(): void
    {
        $this->username = 'edges-' . bin2hex(random_bytes(6));
        $logger = DeployLogger::start($this->username);

        $logger->info("   \e[0m  ");
        $logger->warn('');
        $logger->info('kept');

        $this->assertSame(['kept'], array_column($logger->read()['lines'], 'msg'));
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_a_preparing_deploy_without_its_log_is_no_account_waiting_for_files(): void
    {
        $this->username = 'edges-' . bin2hex(random_bytes(6));
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $log = $logger->getLogPath();
        unset($logger);
        gc_collect_cycles();
        unlink($log);

        $this->assertSame(DeployLogger::STATUS_FAILED, DeployLogger::settleOrphaned($this->username));
        $latest = DeployLogger::readLatestFor($this->username);
        $this->assertSame(DeployLogger::STATUS_FAILED, $latest['status']);
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $latest['error']);
    }
}
