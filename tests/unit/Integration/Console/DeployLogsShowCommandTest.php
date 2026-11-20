<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Tests\TestCase;

/** `pae project:deploy:log --follow` prints a deploy's log until latest.json says it is over. */
class DeployLogsShowCommandTest extends TestCase
{
    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'dl' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** @param list<string> $lines */
    private function deploy(array $lines, ?string $status): DeployLogger
    {
        $logger = DeployLogger::start($this->username);
        foreach ($lines as $line) {
            $logger->info($line);
        }
        if ($status !== null) {
            $logger->finish($status, $status === DeployLogger::STATUS_FAILED ? 'boom' : null);
        }

        return $logger;
    }

    public function test_a_finished_deploy_is_printed_and_its_success_is_the_exit_code(): void
    {
        $this->deploy(['first line', 'second line'], DeployLogger::STATUS_SUCCESS);

        $this->artisan('project:deploy:log', ['project' => $this->username, '--follow' => true])
            ->expectsOutputToContain('first line')
            ->expectsOutputToContain('second line')
            ->expectsOutputToContain('Deploy success.')
            ->assertExitCode(0);
    }

    public function test_a_failed_deploy_exits_1(): void
    {
        $this->deploy(['building'], DeployLogger::STATUS_FAILED);

        $this->artisan('project:deploy:log', ['project' => $this->username, '--follow' => true])
            ->expectsOutputToContain('building')
            ->expectsOutputToContain('Deploy failed.')
            ->assertExitCode(1);
    }

    /** The finish line is the last of the two. */
    public function test_the_backlog_is_cut_to_the_tail(): void
    {
        $this->deploy(['line one', 'line two', 'line three'], DeployLogger::STATUS_SUCCESS);

        $this->artisan('project:deploy:log', ['project' => $this->username, '--follow' => true, '--tail' => 2])
            ->doesntExpectOutputToContain('line one')
            ->doesntExpectOutputToContain('line two')
            ->expectsOutputToContain('line three')
            ->assertExitCode(0);
    }

    public function test_raw_prints_the_json_lines(): void
    {
        $this->deploy(['raw line'], DeployLogger::STATUS_SUCCESS);

        $this->artisan('project:deploy:log', ['project' => $this->username, '--follow' => true, '--raw' => true])
            ->expectsOutputToContain('"msg":"raw line"')
            ->assertExitCode(0);
    }

    public function test_an_older_deploy_is_not_waited_for(): void
    {
        $older = $this->deploy(['older line'], DeployLogger::STATUS_SUCCESS)->getDeployId();
        $this->deploy(['newer line'], DeployLogger::STATUS_SUCCESS);

        $this->artisan('project:deploy:log', ['project' => $this->username, '--follow' => true, '--id' => $older])
            ->expectsOutputToContain('older line')
            ->expectsOutputToContain('This is not the current deploy for the project; nothing more will arrive.')
            ->assertExitCode(0);
    }

    public function test_a_deploy_still_running_at_the_timeout_exits_1(): void
    {
        $logger = $this->deploy(['still going'], null);

        $this->artisan('project:deploy:log', ['project' => $this->username, '--follow' => true, '--timeout' => 1])
            ->expectsOutputToContain('still going')
            ->expectsOutputToContain('Still running after 1s; giving up on watching it.')
            ->assertExitCode(1);
        unset($logger);
    }
}
