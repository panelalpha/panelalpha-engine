<?php

namespace Tests\Unit\Console;

use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\CpuLimitDeferred;
use App\System\Project\Runtime;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A CPU limit changed after an account was created never reached it; the update puts the stored one on every
 * container project, and an account that cannot take it yet is reported without failing the update.
 */
class ApplyCpuLimitCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_container_projects_stored_limit_is_put_on_its_account(): void
    {
        $user = $this->makeUser('dock', ['template' => 'dind', 'cpu_limit' => 3]);
        /** @var Dind&MockInterface $runtime */
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('exists')->andReturn(true);
        $runtime->shouldReceive('applyCpuLimit')->once();
        $this->bindRuntime($user, $runtime);

        $this->artisan('project:cpu-limit:apply --project=dock')
            ->expectsOutputToContain('  Applied.')
            ->assertExitCode(0);
    }

    public function test_a_project_without_an_account_is_left_alone(): void
    {
        $user = $this->makeUser('dock', ['template' => 'dind', 'cpu_limit' => 3]);
        /** @var Dind&MockInterface $runtime */
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('exists')->andReturn(false);
        $runtime->shouldNotReceive('applyCpuLimit');
        $this->bindRuntime($user, $runtime);

        $this->artisan('project:cpu-limit:apply --project=dock')
            ->expectsOutputToContain('nothing to apply')
            ->assertExitCode(0);
    }

    public function test_an_account_that_cannot_take_it_yet_is_reported_in_one_sentence(): void
    {
        $user = $this->makeUser('dock', ['template' => 'dind', 'cpu_limit' => 3]);
        /** @var Dind&MockInterface $runtime */
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('exists')->andReturn(true);
        $runtime->shouldReceive('applyCpuLimit')->once()
            ->andThrow(CpuLimitDeferred::refusedByTheRootBroker("pa-root: refused: there is no action 'docker.limitCpu'\n"));
        $this->bindRuntime($user, $runtime);

        $this->artisan('project:cpu-limit:apply --project=dock')
            ->expectsOutputToContain("is next recreated: the root broker refused it (there is no action 'docker.limitCpu').")
            ->assertExitCode(1);
    }

    public function test_an_update_applies_the_stored_limits_without_failing_on_one(): void
    {
        $installer = $this->repo('scripts/installer.sh');

        $this->assertStringContainsString('php artisan project:cpu-limit:apply --all || true', $installer);
        $this->assertLessThan(
            strpos($installer, 'project:cpu-limit:apply --all'),
            strpos($installer, 'php artisan migrate --force')
        );
        $this->assertStringContainsString('project:cpu-limit:apply --all || warn', $this->repo('scripts/bootstrap-from-source.sh'));
    }

    private function repo(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/' . $path);
    }

    private function bindRuntime(User $user, Dind $runtime): void
    {
        $project = new class (new System(), $user, $runtime) extends ProjectAggregate {
            public function __construct(System $system, User $model, private Runtime $fake)
            {
                parent::__construct($system, $model);
            }

            public function runtime(): Runtime
            {
                return $this->fake;
            }
        };
        $this->app->instance(System::class, new class ($project) extends System {
            public function __construct(private ProjectAggregate $project)
            {
            }

            public function project(User $model): ProjectAggregate
            {
                return $this->project;
            }
        });
    }
}
