<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogArchive;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What is on disk across every account: which deploys are kept, and which are
 * old enough to go.
 *
 * Deploy logs are the only record of what a build did, and nothing else
 * bounds them - an account deploying on every push accumulates them until the
 * host's disk fills, which takes every other account down with it. The
 * pruning has to keep the newest and it has to keep latest.json, because an
 * account whose newest log rotated away still has to be able to say what its
 * last deploy did.
 */
class DeployLogArchiveTest extends TestCase
{
    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        foreach ($this->usernames as $username) {
            DeployLogArchive::forget($username);
        }
        parent::tearDown();
    }

    /**
     * @param list<string> $deployIds newest last
     */
    private function account(array $deployIds, array $latest = []): string
    {
        $username = 'archive-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        $paths = new DeployLogPaths($username);
        (new DeployStatus($paths))->write($latest);
        // Distinct mtimes, oldest first: deploys() orders by modification
        // time, and files written in the same second would tie.
        $when = time() - count($deployIds);
        foreach ($deployIds as $index => $id) {
            file_put_contents($paths->log($id), "{\"ts\":1,\"msg\":\"x\"}\n");
            touch($paths->log($id), $when + $index);
        }

        return $username;
    }

    public function test_an_accounts_deploys_are_listed_newest_first(): void
    {
        $username = $this->account(['20260101-000000-aaa', '20260102-000000-bbb']);

        $ids = array_column(DeployLogArchive::deploys($username), 'id');

        $this->assertSame(['20260102-000000-bbb', '20260101-000000-aaa'], $ids);
    }

    public function test_the_current_deploy_is_marked_and_carries_its_status(): void
    {
        $username = $this->account(
            ['20260101-000000-aaa', '20260102-000000-bbb'],
            ['id' => '20260102-000000-bbb', 'status' => 'success']
        );

        $rows = array_column(DeployLogArchive::deploys($username), null, 'id');

        $this->assertTrue($rows['20260102-000000-bbb']['is_latest']);
        $this->assertSame('success', $rows['20260102-000000-bbb']['status']);
        $this->assertFalse($rows['20260101-000000-aaa']['is_latest']);
        $this->assertNull($rows['20260101-000000-aaa']['status']);
    }

    public function test_an_account_that_never_deployed_lists_nothing(): void
    {
        $this->assertSame([], DeployLogArchive::deploys('archive-nobody-here'));
    }

    public function test_an_account_appears_in_the_host_wide_listing(): void
    {
        $username = $this->account(['20260101-000000-aaa'], ['id' => '20260101-000000-aaa', 'status' => 'success']);

        $rows = array_column(DeployLogArchive::users(), null, 'username');

        $this->assertArrayHasKey($username, $rows);
        $this->assertSame('success', $rows[$username]['status']);
        $this->assertSame(1, $rows[$username]['log_count']);
        $this->assertGreaterThan(0, $rows[$username]['total_bytes']);
    }

    public function test_pruning_keeps_the_newest_and_deletes_the_rest(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = sprintf('2026010%d-000000-aaa', $i);
        }
        $username = $this->account($ids);

        $deleted = DeployLogArchive::prune($username, 2);

        $this->assertCount(3, $deleted);
        $this->assertSame(
            ['20260105-000000-aaa', '20260104-000000-aaa'],
            array_column(DeployLogArchive::deploys($username), 'id')
        );
    }

    public function test_pruning_leaves_the_status_pointer_alone(): void
    {
        // The panel polls it, and an account whose newest log just rotated
        // away still has to be able to say what its last deploy did.
        $username = $this->account(
            ['20260101-000000-aaa', '20260102-000000-bbb'],
            ['id' => '20260102-000000-bbb', 'status' => 'success']
        );

        DeployLogArchive::prune($username, 1);

        $this->assertFileExists((new DeployLogPaths($username))->latest());
    }

    public function test_pruning_an_account_within_its_limit_deletes_nothing(): void
    {
        $username = $this->account(['20260101-000000-aaa', '20260102-000000-bbb']);

        $this->assertSame([], DeployLogArchive::prune($username, 10));
        $this->assertCount(2, DeployLogArchive::deploys($username));
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        $username = $this->account(['20260101-000000-aaa', '20260102-000000-bbb', '20260103-000000-ccc']);

        $deleted = DeployLogArchive::prune($username, 1, true);

        $this->assertCount(2, $deleted);
        $this->assertCount(3, DeployLogArchive::deploys($username));
    }

    public function test_keeping_nothing_is_refused(): void
    {
        // Silently deleting every log an account has is not a thing a
        // housekeeping job should be able to be asked for by accident.
        $this->expectException(InvalidArgumentException::class);

        DeployLogArchive::prune($this->account([]), 0);
    }

    public function test_pruning_every_account_reports_only_those_it_touched(): void
    {
        $busy = $this->account(['20260101-000000-aaa', '20260102-000000-bbb', '20260103-000000-ccc']);
        $quiet = $this->account(['20260101-000000-aaa']);

        $result = DeployLogArchive::pruneAll(1, true);

        $this->assertArrayHasKey($busy, $result);
        $this->assertArrayNotHasKey($quiet, $result);
    }

    public function test_forgetting_an_account_removes_everything_it_had(): void
    {
        $username = $this->account(['20260101-000000-aaa'], ['id' => '20260101-000000-aaa']);
        $dir = (new DeployLogPaths($username))->directory();

        DeployLogArchive::forget($username);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_forgetting_an_account_with_a_dot_in_its_name_works(): void
    {
        // Its own pattern here used to exclude dots, so a legitimate
        // `acme.shop` was silently skipped and its logs stayed forever.
        $username = 'archive.' . bin2hex(random_bytes(5));
        $this->usernames[] = $username;
        (new DeployStatus(new DeployLogPaths($username)))->write(['id' => 'x']);

        DeployLogArchive::forget($username);

        $this->assertDirectoryDoesNotExist((new DeployLogPaths($username))->directory());
    }

    public function test_forgetting_a_name_that_could_escape_does_nothing(): void
    {
        DeployLogArchive::forget('../other');
        DeployLogArchive::forget('..');

        $this->assertDirectoryExists(DeployLogPaths::base());
    }

    public function test_forgetting_an_account_that_never_deployed_is_harmless(): void
    {
        DeployLogArchive::forget('archive-nobody-here');

        $this->addToAssertionCount(1);
    }
}
