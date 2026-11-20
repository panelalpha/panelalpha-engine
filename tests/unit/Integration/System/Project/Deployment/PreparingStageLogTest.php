<?php

namespace Tests\Integration\System\Project\Deployment;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DindDeployMechanics;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** A new account's preparing stage logged nothing between its start and the cloning stage. */
class PreparingStageLogTest extends TestCase
{
    private string $username = '';

    private ?DeployLogger $deploy = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'prep-'.bin2hex(random_bytes(4));
        $this->deploy = DeployLogger::start($this->username);
    }

    protected function tearDown(): void
    {
        $this->deploy = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_each_step_is_announced_before_it_runs(): void
    {
        $this->prepare(daemonAnswers: true);

        $this->assertSame([
            ['info', "Creating the account's directories and Linux user"],
            ['info', "Writing the account's compose file"],
            ['info', 'Starting the account container and waiting for its Docker daemon'],
            ['info', 'Handing the home to the account and setting its disk quota'],
            ['ok', "The account's Docker daemon answered 0s after the container start"],
        ], $this->lines());
    }

    public function test_a_daemon_that_never_answered_is_a_warning_not_silence(): void
    {
        $this->prepare(daemonAnswers: false);

        $this->assertSame(
            ['warn', "The account's Docker daemon did not answer within 0s of the container start;"
                .' the deploy goes on, and its next docker command reports why'],
            $this->lines()[4],
        );
    }

    private function prepare(bool $daemonAnswers): void
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('hostingExists')->andReturn(false);
        $project->shouldReceive('createDirectories')->once();
        $project->shouldReceive('syncLinuxUser')->once()->andReturn(['UID' => 1000, 'GID' => 1000]);
        $project->shouldReceive('model')->andReturn(Mockery::mock(ModelsUser::class, ['save' => true]));
        $project->shouldReceive('createFromTemplate')->once();
        $project->shouldReceive('up')->once();
        $project->shouldReceive('fixPermissions')->once();
        $project->shouldReceive('configureQuota')->once();
        $project->shouldReceive('waitForAllRunning')->once();

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('project')->andReturn($project);
        $dind->shouldReceive('username')->andReturn($this->username);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));
        $dind->shouldReceive('system')->andReturn(new RecordingSystem);
        $dind->shouldReceive('innerDockerIsUp')->once()->andReturn($daemonAnswers);

        (new DindDeployMechanics($dind))->prepareHostingEnvironment();
    }

    /** @return list<array{string, string}> */
    private function lines(): array
    {
        return array_map(
            static fn (array $line): array => [$line['level'], $line['msg']],
            $this->deploy->entries(),
        );
    }
}
