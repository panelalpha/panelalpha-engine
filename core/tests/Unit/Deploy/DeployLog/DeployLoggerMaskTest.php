<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Telemetry\Redactor;
use App\Lib\Deploy\Telemetry\TelemetryFields;
use Tests\TestCase;

/**
 * A value registered with mask() never reaches the stored log, the streamed
 * frames, the error, or the tail telemetry reads — whichever logger instance
 * on the same deploy wrote it.
 */
class DeployLoggerMaskTest extends TestCase
{
    private const SECRET = 'Kd83hQm2Lx9Vb4Nt7Pz1Rw6Y';

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

    private function started(): DeployLogger
    {
        $username = 'mask-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        return $logger;
    }

    public function test_masked_values_never_reach_the_log_file(): void
    {
        $logger = $this->started();
        $logger->mask([self::SECRET]);

        $logger->info('password=' . self::SECRET);
        $logger->writeProcessBuffer('out', "seed | user admin / " . substr(self::SECRET, 0, 10));
        $logger->writeProcessBuffer('out', substr(self::SECRET, 10) . " created\n");

        $lines = array_column($logger->read()['lines'], 'msg');
        $this->assertContains('password=***', $lines);
        $this->assertContains('seed | user admin / *** created', $lines, 'a value split across output chunks is joined first');
        $this->assertStringNotContainsString(self::SECRET, (string) file_get_contents($logger->getLogPath()));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_another_logger_on_the_same_deploy_masks_it_too(): void
    {
        $logger = $this->started();
        $logger->mask([self::SECRET]);

        $other = DeployLogger::forDeploy($this->usernames[0], $logger->getDeployId());
        $other->info('echo ' . self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, (string) file_get_contents($logger->getLogPath()));
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_the_error_and_the_telemetry_tail_are_masked(): void
    {
        $logger = $this->started();
        $logger->mask([self::SECRET, 'abc']);
        $logger->info('seeding abc with ' . self::SECRET);
        $logger->recordFailureOutput('curl: (22) 401 for admin:' . self::SECRET);
        $logger->finish(DeployLogger::STATUS_FAILED, 'Seed failed for ' . self::SECRET);

        $this->assertSame('Seed failed for ***', $logger->readLatest()['error']);
        $tail = (new TelemetryFields($this->usernames[0]))->logTail($logger, 80);
        $this->assertNotSame([], $tail);
        $this->assertStringNotContainsString(self::SECRET, implode("\n", Redactor::tail($tail)));
        $this->assertStringNotContainsString(self::SECRET, implode("\n", $tail));
        // Too short to mask without garbling ordinary words.
        $this->assertContains('seeding abc with ***', $tail);
    }

    public function test_a_finished_deploy_forgets_its_values(): void
    {
        $logger = $this->started();
        $logger->mask([self::SECRET]);
        $logger->finish(DeployLogger::STATUS_SUCCESS);

        $this->assertSame('x ' . self::SECRET, $logger->redact('x ' . self::SECRET));
    }
}
