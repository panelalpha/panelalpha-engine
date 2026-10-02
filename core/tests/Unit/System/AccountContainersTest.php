<?php

namespace Tests\Unit\System;

use App\System;
use App\System\AccountContainers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;

class AccountContainersTest extends TestCase
{
    private const USERS = '/opt/panelalpha/shared-hosting/users';

    /** `docker ps` lines in the shape the engine asks for, labels as Compose writes them on a host. */
    private const PS = "shared-hosting-core-1\trunning\t/opt/panelalpha/shared-hosting\n"
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
        $this->assertSame([[
            'sudo', 'docker', 'ps', '-a',
            '--filter', 'label=com.docker.compose.project.working_dir',
            '--format', '{{.Names}}\t{{.State}}\t{{.Label "com.docker.compose.project.working_dir"}}',
        ]], $system->ran);
    }

    public function test_a_failed_listing_is_a_warning_not_a_clean_bill(): void
    {
        $system = $this->system(FakeProcess::failed('Cannot connect to the Docker daemon'));

        $report = (new AccountContainers($system))->orphanReport([]);

        $this->assertSame(0, $report['count']);
        $this->assertStringContainsString('Cannot connect to the Docker daemon', (string) $report['warning']);
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
