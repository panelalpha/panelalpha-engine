<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use Tests\TestCase;

/**
 * `latest.json`: which deploy an account is on, how far it got, and whether
 * it is still going.
 *
 * The panel polls this file while the deploy request is still writing it, so
 * a reader must never see a half-written record - which is why every update
 * is written whole and renamed into place rather than edited.
 */
class DeployStatusTest extends TestCase
{
    private string $username = '';

    private DeployStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'status-' . bin2hex(random_bytes(6));
        $this->status = new DeployStatus(new DeployLogPaths($this->username));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_an_account_that_never_deployed_has_no_status(): void
    {
        $this->assertNull($this->status->read());
        $this->assertNull($this->status->value('status'));
        $this->assertFalse($this->status->is('running'));
    }

    public function test_a_record_survives_a_write_and_a_read(): void
    {
        $this->status->write(['id' => 'dep_1', 'status' => 'running']);

        $this->assertSame(['id' => 'dep_1', 'status' => 'running'], $this->status->read());
    }

    public function test_a_reader_never_sees_a_partial_record(): void
    {
        // Written whole and renamed. A poll landing between the two sees the
        // previous complete record, not half of the next one.
        $this->status->write(['id' => 'dep_1', 'status' => 'running']);
        $this->status->write(['id' => 'dep_2', 'status' => 'success']);

        $raw = (string) file_get_contents((new DeployLogPaths($this->username))->latest());

        $this->assertSame(['id' => 'dep_2', 'status' => 'success'], json_decode($raw, true));
    }

    public function test_an_update_merges_rather_than_replaces(): void
    {
        // A stage transition must not lose the deploy id or its start time.
        $this->status->write(['id' => 'dep_1', 'status' => 'running', 'started_at' => 1000]);
        $latest = $this->status->update(['stage' => 'building']);

        $this->assertSame('dep_1', $latest['id']);
        $this->assertSame(1000, $latest['started_at']);
        $this->assertSame('building', $latest['stage']);
        $this->assertSame($latest, $this->status->read());
    }

    public function test_an_update_on_an_empty_record_writes_the_changes(): void
    {
        $this->assertSame(['status' => 'running'], $this->status->update(['status' => 'running']));
    }

    public function test_a_single_field_is_readable(): void
    {
        $this->status->write(['id' => 'dep_1', 'status' => 'success']);

        $this->assertSame('dep_1', $this->status->value('id'));
        $this->assertNull($this->status->value('nothing'));
        $this->assertTrue($this->status->is('success'));
        $this->assertFalse($this->status->is('running'));
    }

    public function test_an_unreadable_record_reads_as_no_record(): void
    {
        // A truncated latest.json must not throw out of a status poll.
        $this->status->write(['id' => 'dep_1']);
        file_put_contents((new DeployLogPaths($this->username))->latest(), '{"id": "dep_1"');

        $this->assertNull($this->status->read());
    }

    public function test_a_fresh_record_describes_a_deploy_about_to_run(): void
    {
        $started = DeployStatus::started('dep_1');

        $this->assertSame('dep_1', $started['id']);
        $this->assertSame(DeployLogger::STATUS_RUNNING, $started['status']);
        $this->assertNull($started['finished_at']);
        $this->assertNull($started['error']);
        $this->assertSame([], $started['stages']);
        $this->assertGreaterThan(0, $started['started_at']);
    }

    public function test_the_open_stage_is_closed_and_named(): void
    {
        $stages = [
            ['name' => 'preparing', 'started_at' => 100, 'finished_at' => 200],
            ['name' => 'building', 'started_at' => 200, 'finished_at' => null],
        ];

        [$closed, $name] = DeployStatus::closeOpenStage($stages, 300);

        $this->assertSame('building', $name);
        $this->assertSame(300, $closed[1]['finished_at']);
    }

    public function test_a_stage_list_that_is_already_closed_is_left_alone(): void
    {
        // Closing twice would overwrite the real finish time with the time of
        // the second call, silently lengthening the stage in every report.
        $stages = [['name' => 'building', 'started_at' => 100, 'finished_at' => 200]];

        [$closed, $name] = DeployStatus::closeOpenStage($stages, 300);

        $this->assertNull($name);
        $this->assertSame($stages, $closed);
    }

    public function test_no_stages_means_nothing_to_close(): void
    {
        [$closed, $name] = DeployStatus::closeOpenStage([], 300);

        $this->assertNull($name);
        $this->assertSame([], $closed);
    }
}
