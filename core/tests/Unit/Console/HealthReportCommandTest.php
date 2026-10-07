<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Deploy\HealthReportCommand;
use App\Models\User;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\PhpHosting;
use Mockery;
use Tests\TestCase;

/**
 * The sweep reaches a DinD account through the project's runtime, not the
 * System\Project wrapper that User::project() returns.
 */
class HealthReportCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_dind_account_is_swept_through_its_runtime(): void
    {
        $dind = Mockery::mock(Dind::class);
        $user = $this->user('dind', 'active', $dind);

        $this->assertSame($dind, $this->projectFor($user));
    }

    public function test_a_php_hosting_runtime_is_skipped(): void
    {
        $user = $this->user('dind', 'active', Mockery::mock(PhpHosting::class));

        $this->assertNull($this->projectFor($user));
    }

    public function test_a_suspended_or_non_dind_account_is_skipped(): void
    {
        $this->assertNull($this->projectFor($this->user('dind', 'suspended', Mockery::mock(Dind::class))));
        $this->assertNull($this->projectFor($this->user('wordpress', 'active', Mockery::mock(Dind::class))));
    }

    public function test_an_account_whose_project_cannot_be_built_is_skipped(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->status = 'active';
        $user->shouldReceive('getTemplate')->andReturn('dind');
        $user->shouldReceive('project')->andThrow(new \RuntimeException('no system'));

        $this->assertNull($this->projectFor($user));
    }

    private function user(string $template, string $status, object $runtime): User
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($runtime);

        $user = Mockery::mock(User::class)->makePartial();
        $user->status = $status;
        $user->shouldReceive('getTemplate')->andReturn($template);
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }

    private function projectFor(User $user): ?Dind
    {
        $method = new \ReflectionMethod(HealthReportCommand::class, 'projectFor');

        return $method->invoke(new HealthReportCommand(), $user);
    }
}
