<?php

namespace Tests\Unit\System;

use App\System;
use App\System\AccountContainers;
use App\System\ComposeProject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

class AccountContainersTest extends TestCase
{
    private const USERS = '/opt/panelalpha/shared-hosting/users';

    /** `docker ps` lines in the shape the engine asks for, labels as Compose writes them on a host. */
    private const PS = ComposeProject::NAME . "-core-1\trunning\t/opt/panelalpha/shared-hosting\n"
        . "rvgotify\trunning\t" . self::USERS . "/rvgotify\n"
        . "swkorphan\texited\t" . self::USERS . "/swkorphan\n"
        . "hubtest\trunning\t/root/hubtest\n"
        . "pwbd1c1d74\trunning\t" . self::USERS . "/pwbd1c1d74\n";

    public function test_reports_only_account_containers_no_project_owns(): void
    {
        $orphans = AccountContainers::orphansIn(self::PS, self::USERS, ['rvgotify', 'pwbd1c1d74']);

        $this->assertSame([[
            'name' => 'swkorphan',
            'state' => 'exited',
            'project' => 'swkorphan',
            'cleanup' => 'docker rm -f swkorphan',
        ]], $orphans);
    }

    public function test_a_foreign_container_is_never_reported_whatever_its_name(): void
    {
        // Named like a missing account, but Compose did not start it from an account dir.
        $ps = "ghost\texited\t/root/ghost\nghost2\tcreated\t" . self::USERS . "\n";

        $this->assertSame([], AccountContainers::orphansIn($ps, self::USERS, []));
    }

    public function test_the_report_names_each_container_and_its_cleanup_command(): void
    {
        $report = AccountContainers::report(AccountContainers::orphansIn(self::PS, self::USERS, ['rvgotify']));

        $this->assertSame(2, $report['count']);
        $this->assertSame(
            '2 account container(s) on the host belong to no project: swkorphan (exited), pwbd1c1d74 (running). '
            . 'A project create or delete that did not finish, or a reset engine database, left them behind, and a '
            . 'new project cannot take their name. The engine does not remove them. To remove them, run on the '
            . 'host: docker rm -f swkorphan; docker rm -f pwbd1c1d74',
            $report['warning']
        );
    }

    public function test_no_orphans_means_no_warning(): void
    {
        $this->assertSame(
            ['count' => 0, 'containers' => [], 'warning' => null],
            AccountContainers::report([])
        );
    }

    public function test_reads_labelled_containers_from_docker_and_removes_nothing(): void
    {
        $system = $this->system(FakeProcess::ok(self::PS));

        $report = (new AccountContainers($system))->orphanReport(['rvgotify', 'pwbd1c1d74']);

        $this->assertSame(1, $report['count']);
        $this->assertCount(1, $system->ran);
        $this->assertTrue(LocalRoot::isRequest($system->ran[0]));
        $this->assertSame('docker.containersLabelled', LocalRoot::action($system->ran[0]));
        $this->assertSame([AccountContainers::WORKING_DIR_LABEL], LocalRoot::values($system->ran[0]));
        $this->assertSame([], LocalRoot::plain($system->ran[0]));
    }

    public function test_a_report_within_some_accounts_names_only_their_orphans(): void
    {
        $owned = ['rvgotify'];

        $within = (new AccountContainers($this->system(FakeProcess::ok(self::PS))))->orphanReport($owned, ['pwbd1c1d74', 'rvgotify']);
        $none = (new AccountContainers($this->system(FakeProcess::ok(self::PS))))->orphanReport($owned, []);
        $every = (new AccountContainers($this->system(FakeProcess::ok(self::PS))))->orphanReport($owned);

        $this->assertSame(['pwbd1c1d74'], array_column($within['containers'], 'project'));
        $this->assertStringNotContainsString('swkorphan', (string) $within['warning']);
        $this->assertSame(['count' => 0, 'containers' => [], 'warning' => null], $none);
        $this->assertSame(['swkorphan', 'pwbd1c1d74'], array_column($every['containers'], 'project'));
    }

    public function test_a_failed_listing_is_a_warning_not_a_clean_bill(): void
    {
        $system = $this->system(FakeProcess::failed('Cannot connect to the Docker daemon'));

        $report = (new AccountContainers($system))->orphanReport([]);

        $this->assertSame(0, $report['count']);
        $this->assertStringContainsString('Cannot connect to the Docker daemon', (string) $report['warning']);
    }

    /** One listing for the IDs and one inspect for all of them; a foreign container sharing nothing but a label is left out. */
    public function test_running_accounts_carry_their_runtime_and_start_from_one_typed_inspect(): void
    {
        $system = $this->answering([
            FakeProcess::ok("aaaa\nbbbb\ncccc\n"),
            FakeProcess::of(1, "/alice\tsysbox-runc\t".self::USERS."/alice\t2026-10-10T15:00:00.123456789Z\t\"\"\n"
                ."/hubtest\trunc\t/root/hubtest\t2026-10-10T14:00:00Z\t\"\"\n"
                ."/bob\ttrunc\t".self::USERS."/bob\t2026-10-10T15:10:00.5Z\t\"boom\"\n", 'error: no such object: dddd'),
        ]);

        $running = (new AccountContainers($system))->running();

        $this->assertSame([
            'alice' => ['runtime' => 'sysbox-runc', 'startedAt' => '2026-10-10T15:00:00.123456789Z'],
            'bob' => ['runtime' => 'trunc', 'startedAt' => '2026-10-10T15:10:00.5Z'],
        ], $running);
        $this->assertCount(2, $system->ran);
        $this->assertSame('docker.inspect', LocalRoot::action($system->ran[1]));
        $this->assertSame(['aaaa', 'bbbb', 'cccc'], LocalRoot::values($system->ran[1]));
    }

    public function test_a_failed_start_is_the_containers_last_error(): void
    {
        $system = $this->answering([
            FakeProcess::ok("cccc\n"),
            FakeProcess::ok("/carol\tsysbox-runc\t".self::USERS."/carol\t0001-01-01T00:00:00Z\t\"db down\"\n"
                ."/dave\tsysbox-runc\t".self::USERS."/dave\t2026-10-10T15:00:00Z\t\"\"\n"),
        ]);

        $this->assertSame(
            ['carol' => ['runtime' => 'sysbox-runc', 'error' => 'db down']],
            (new AccountContainers($system))->failedStarts()
        );
    }

    public function test_an_inspect_of_containers_all_gone_answers_null(): void
    {
        $system = $this->answering([FakeProcess::ok("cccc\n"), FakeProcess::failed('error: no such object: cccc')]);

        $this->assertNull((new AccountContainers($system))->failedStarts());
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

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = $cmd;

                return array_shift($this->answers) ?? FakeProcess::ok();
            }
        };
    }

    private function system(Process $answer): System
    {
        return new class ($answer) extends System {
            /** @var list<string|list<string>> */
            public array $ran = [];

            public function __construct(private Process $answer)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = $cmd;

                return $this->answer;
            }
        };
    }
}
