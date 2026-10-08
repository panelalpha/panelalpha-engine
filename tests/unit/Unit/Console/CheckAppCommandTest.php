<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Deploy\CheckAppCommand;
use App\Models\User;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\PhpHosting;
use Mockery;
use Tests\TestCase;

/**
 * project:deploy:check reaches the DinD driver through the project's runtime,
 * not the System\Project wrapper that User::project() returns.
 */
class CheckAppCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_dind_runtime_is_checked(): void
    {
        $dind = Mockery::mock(Dind::class);

        $this->assertSame($dind, $this->dindOf($this->user($dind)));
    }

    public function test_a_php_hosting_runtime_is_refused(): void
    {
        $this->assertNull($this->dindOf($this->user(Mockery::mock(PhpHosting::class))));
    }

    private function user(object $runtime): User
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($runtime);

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }

    private function dindOf(User $user): ?Dind
    {
        return (new \ReflectionMethod(CheckAppCommand::class, 'dindOf'))->invoke(null, $user);
    }
}
