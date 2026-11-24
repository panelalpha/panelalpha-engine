<?php

namespace Tests\Integration\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Project\ProjectDeployState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** latest.json's verdict, kept in the database for the project list. */
class ProjectDeployStateTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->username = 'state-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_every_write_of_latest_json_is_mirrored(): void
    {
        $this->migrate();
        $status = new DeployStatus(new DeployLogPaths($this->username));

        $status->write(['id' => 'dep_1', 'status' => 'running', 'finished_at' => null, 'stage' => 'cloning']);
        $this->assertSame([$this->username => ['status' => 'running', 'finished_at' => null]], ProjectDeployState::of([$this->username]));

        $status->update(['status' => 'success', 'finished_at' => 1764000000]);
        $this->assertSame([$this->username => ['status' => 'success', 'finished_at' => 1764000000]], ProjectDeployState::of([$this->username]));
    }

    public function test_deleting_the_logs_forgets_the_state(): void
    {
        $this->migrate();
        (new DeployStatus(new DeployLogPaths($this->username)))->write(['id' => 'dep_1', 'status' => 'failed']);

        DeployLogger::deleteUserLogs($this->username);

        $this->assertSame(0, DB::table('project_deploy_states')->where('username', $this->username)->count());
    }

    public function test_a_write_before_the_table_exists_still_writes_latest_json(): void
    {
        $status = new DeployStatus(new DeployLogPaths($this->username));

        $status->write(['id' => 'dep_1', 'status' => 'success']);

        $this->assertSame(['id' => 'dep_1', 'status' => 'success'], $status->read());
        $this->assertSame([], ProjectDeployState::of([$this->username]));
    }

    public function test_the_migration_takes_over_what_latest_json_already_says(): void
    {
        (new DeployStatus(new DeployLogPaths($this->username)))->write(['id' => 'dep_9', 'status' => 'partial', 'finished_at' => 1764000000]);

        $this->migrate();

        $this->assertSame(['status' => 'partial', 'finished_at' => 1764000000], ProjectDeployState::of([$this->username])[$this->username] ?? null);
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }
}
