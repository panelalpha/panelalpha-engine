<?php

namespace Tests\Integration\System\Project\Deployment;

use App\Lib\Domains\PublicUrl;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\TemplateDeployMechanics;
use App\System\Project\Dind\AppHealth;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The template mechanics read the project's details and hand the app's lifecycle to the project. */
class TemplateDeployMechanicsDelegationTest extends TestCase
{
    use InMemoryDatabase;
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_repository_and_a_dind_template_without_one_are_told_apart(): void
    {
        $git = new TemplateDeployMechanics($this->projectFor($this->makeUser('alice', ['template' => 'dind', 'git_repo' => 'https://example.test/a.git'])));
        $bare = new TemplateDeployMechanics($this->projectFor($this->makeUser('bob', ['template' => 'dind'])));
        $static = new TemplateDeployMechanics($this->projectFor($this->makeUser('carol', ['template' => 'static'])));

        $this->assertTrue($git->hasGitProject());
        $this->assertFalse($git->isDindWithoutGit());
        $this->assertFalse($bare->hasGitProject());
        $this->assertTrue($bare->isDindWithoutGit());
        $this->assertFalse($static->isDindWithoutGit());
    }

    public function test_running_starting_and_aborting_are_the_projects_own(): void
    {
        $project = $this->projectFor($this->makeUser('alice'));
        $project->shouldReceive('isRunning')->once()->andReturn(true);
        $project->shouldReceive('startUserApp')->once()->andReturn(['exit_code' => 0, 'stdout' => 'up', 'stderr' => '']);
        $project->shouldReceive('abortRunningDeploy')->with(removeVolumes: true)->once();
        $mechanics = new TemplateDeployMechanics($project);

        $this->assertTrue($mechanics->isApplicationEnvironmentRunning());
        $this->assertSame(['exit_code' => 0, 'stdout' => 'up', 'stderr' => ''], $mechanics->startApplication());
        $mechanics->abortPartialDeploy(true);
    }

    public function test_an_app_that_answered_on_no_port_is_a_serving_warning(): void
    {
        $healthy = new TemplateDeployMechanics($this->projectFor($this->makeUser('alice')));
        $silent = new TemplateDeployMechanics($this->projectFor($this->makeUser('bob', [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 80, 'status' => 'refused', 'http_code' => null]],
        ])));

        $this->assertSame([], $healthy->servingWarnings());
        $this->assertSame([AppHealth::CODE_NOT_ANSWERING], array_map(static fn ($finding) => $finding->code, $silent->servingWarnings()));
    }

    public function test_the_public_url_warning_names_the_accounts_own_domain_not_the_one_passed(): void
    {
        $mechanics = new TemplateDeployMechanics($this->projectFor(
            $this->makeUser('alice', ['domain' => ['publicly_resolvable' => false]], 'alice.example.test'),
        ));
        $other = new DomainModel;
        $other->domain = 'elsewhere.example.test';

        $warnings = $mechanics->publicUrlWarnings($other);

        $this->assertSame([PublicUrl::UNRESOLVABLE], array_map(static fn ($finding) => $finding->code, $warnings));
        $this->assertSame('alice.example.test', $warnings[0]->facts['domain']);
    }

    private function projectFor(ModelsUser $user): SystemProject&Mockery\MockInterface
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('model')->andReturn($user);

        return $project;
    }
}
