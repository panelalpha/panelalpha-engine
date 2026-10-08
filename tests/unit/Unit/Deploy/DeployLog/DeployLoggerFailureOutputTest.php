<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Tests\TestCase;

/**
 * finish() is given the sentence a *customer* should read, which by then has
 * usually had the BuildKit dump thrown away. Telemetry needs the dump — running
 * the explainer over an already-explained sentence recognises nothing, so a
 * diagnosed failure would be reported as one nobody has a rule for.
 *
 * recordFailureOutput() is how a caller keeps the raw text for that. What it
 * must never do is change what the customer sees, which is what this pins.
 */
class DeployLoggerFailureOutputTest extends TestCase
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
        $username = 'rawout-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }

    public function test_recording_raw_output_does_not_change_the_customer_facing_message(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $logger->recordFailureOutput(
            "#18 ERROR: process \"/bin/sh -c npm ci\" did not complete successfully: exit code: 1"
        );
        $logger->finish(
            DeployLogger::STATUS_FAILED,
            'Failed to start app: The build ran out of disk space.'
        );

        $lines = array_column($logger->read()['lines'], 'msg');
        $last = end($lines);

        $this->assertStringContainsString('The build ran out of disk space.', $last);
        $this->assertStringNotContainsString('#18 ERROR', $last);
        $this->assertSame(
            'Failed to start app: The build ran out of disk space.',
            $logger->readLatest()['error']
        );
    }

    public function test_recording_writes_no_extra_log_line(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $before = count($logger->read()['lines']);

        $logger->recordFailureOutput('some raw build output');

        $this->assertCount($before, $logger->read()['lines']);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_blank_output_is_ignored(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->recordFailureOutput(null);
        $logger->recordFailureOutput('   ');

        // Nothing to assert beyond "this did not blow up and the log still
        // closes cleanly" — the guard exists so an empty stderr does not
        // shadow the message finish() was given.
        $logger->finish(DeployLogger::STATUS_FAILED, 'Some failure');

        $this->assertSame('Some failure', $logger->readLatest()['error']);
    }

    public function test_tail_returns_the_last_lines_in_order(): void
    {
        $logger = DeployLogger::start($this->username());
        for ($i = 0; $i < 10; $i++) {
            $logger->info("line {$i}");
        }

        $tail = $logger->tail(3);

        $this->assertSame(['line 7', 'line 8', 'line 9'], array_column($tail, 'msg'));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_a_precheck_rejection_survives_finish_and_a_new_deploy_clears_it(): void
    {
        // The precheck and the controller that finishes the deploy hold
        // different logger instances; the mark has to go through latest.json.
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->stage(DeployLogger::STAGE_CLONING);
        DeployLogger::current($username)?->markPreCheckRejected();
        $logger->finish(DeployLogger::STATUS_FAILED, 'Error: Less than 10GB of disk space available.');

        $this->assertTrue($logger->readLatest()[DeployLogger::PRECHECK_REJECTED] ?? null);

        $next = DeployLogger::start($username);
        $this->assertArrayNotHasKey(DeployLogger::PRECHECK_REJECTED, $next->readLatest());
        $next->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_tail_of_a_log_that_does_not_exist_is_empty(): void
    {
        $logger = DeployLogger::forDeploy($this->username(), '20260825-000000-abcdef');

        $this->assertSame([], $logger->tail(10));
    }
}
