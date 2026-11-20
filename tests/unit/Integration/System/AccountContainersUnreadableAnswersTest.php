<?php

namespace Tests\Integration\System;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\AccountContainers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;

/** Listings Docker could not give, or gave in a shape the engine does not ask for. */
class AccountContainersUnreadableAnswersTest extends TestCase
{
    private const USERS = '/opt/panelalpha/shared-hosting/users';

    public function test_no_labelled_container_means_no_inspect_and_no_running_account(): void
    {
        $system = $this->answering([FakeProcess::ok("\n")]);

        $this->assertSame([], (new AccountContainers($system))->running());
        $this->assertCount(1, $system->ran);
    }

    public function test_a_listing_docker_refuses_answers_null(): void
    {
        $system = $this->answering([FakeProcess::failed('Cannot connect to the Docker daemon')]);

        $this->assertNull((new AccountContainers($system))->failedStarts());
        $this->assertCount(1, $system->ran);
    }

    public function test_an_inspect_line_with_missing_fields_is_skipped(): void
    {
        $system = $this->answering([
            FakeProcess::ok("aaaa\nbbbb\n"),
            FakeProcess::ok("/alice\tsysbox-runc\t" . self::USERS . "/alice\n"
                . "/bob\tsysbox-runc\t" . self::USERS . "/bob\t2026-10-10T15:00:00Z\t\"\"\n"),
        ]);

        $this->assertSame(
            ['bob' => ['runtime' => 'sysbox-runc', 'startedAt' => '2026-10-10T15:00:00Z']],
            (new AccountContainers($system))->running()
        );
    }

    public function test_a_ps_line_with_missing_fields_is_no_orphan(): void
    {
        $orphans = AccountContainers::orphansIn(
            "half\trunning\nswkorphan\texited\t" . self::USERS . "/swkorphan\n",
            self::USERS,
            [],
        );

        $this->assertSame(['swkorphan'], array_column($orphans, 'name'));
    }

    /**
     * @param  list<Process>  $answers
     */
    private function answering(array $answers): System
    {
        return new class ($answers) extends System {
            /** @var list<string|list<string>> */
            public array $ran = [];

            /** @param  list<Process>  $answers */
            public function __construct(private array $answers)
            {
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = $cmd;

                return array_shift($this->answers) ?? FakeProcess::ok();
            }
        };
    }
}
