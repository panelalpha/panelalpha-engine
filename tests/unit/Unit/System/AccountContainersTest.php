<?php

namespace Tests\Unit\System;

use App\System\AccountContainers;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountContainersFixtures;

class AccountContainersTest extends TestCase
{
    use AccountContainersFixtures;

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
}
