<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Deployment\TemplateDeployMechanics;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The template half of the deploy pipeline, lifted out of UserController.
 */
class TemplateDeployMechanicsTest extends TestCase
{
    use InMemoryDatabase;
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /** @param array<string, mixed> $details */
    private function projectFor(ModelsUser $user): Mockery\MockInterface
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('model')->andReturn($user);

        return $project;
    }

    public function test_it_satisfies_the_same_contract_as_the_dind_mechanics(): void
    {
        $this->assertTrue(is_subclass_of(TemplateDeployMechanics::class, DeployMechanics::class));
    }

    public function test_prepare_runs_the_host_steps_and_records_the_os_ids(): void
    {
        $user = $this->makeUser('alice');
        $project = $this->projectFor($user);
        $project->shouldReceive('createDirectories')->once();
        $project->shouldReceive('syncLinuxUser')->once()->andReturn(['UID' => 1234, 'GID' => 5678]);
        $project->shouldReceive('createFromTemplate')->once();
        $project->shouldReceive('up')->once();
        $project->shouldReceive('fixPermissions')->once();
        $project->shouldReceive('configureQuota')->once();
        $project->shouldReceive('waitForAllRunning')->once();

        (new TemplateDeployMechanics($project))->prepareHostingEnvironment();

        $this->assertSame(1234, ModelsUser::findByUsername('alice')->getDetails()['UID']);
        $this->assertSame(5678, ModelsUser::findByUsername('alice')->getDetails()['GID']);
    }

    public function test_a_template_project_has_no_source_to_clone(): void
    {
        $project = $this->projectFor($this->makeUser('alice'));
        $project->shouldReceive('prepareUserAppFromSources')->once();
        $project->shouldNotReceive('cloneUserApp');

        (new TemplateDeployMechanics($project))->ingestApplicationSource();

        $this->addToAssertionCount(1);
    }

    public function test_a_project_with_a_repository_is_pre_checked_and_cloned(): void
    {
        $user = $this->makeUser('alice', ['git_repo' => 'https://example.test/a.git']);
        $project = $this->projectFor($user);
        $project->shouldReceive('preCheckUserApp')->once();
        $project->shouldReceive('cloneUserApp')->once();
        $project->shouldNotReceive('prepareUserAppFromSources');

        (new TemplateDeployMechanics($project))->ingestApplicationSource();

        $this->addToAssertionCount(1);
    }

    public function test_a_project_without_a_main_domain_is_refused_by_name(): void
    {
        $project = $this->projectFor($this->makeUser('alice'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Project 'alice' has no main domain.");

        (new TemplateDeployMechanics($project))->requireMainDomain();
    }

    public function test_the_main_domain_is_returned_when_there_is_one(): void
    {
        $user = $this->makeUser('alice');
        $this->makeMainDomain($user, 'alice.example.test');

        $domain = (new TemplateDeployMechanics($this->projectFor($user)))->requireMainDomain();

        $this->assertSame('alice.example.test', $domain->domain);
    }

    public function test_publishing_the_domain_creates_its_project_config(): void
    {
        $user = $this->makeUser('alice');

        $domainProject = Mockery::mock(\App\System\Project\Domain::class);
        $domainProject->shouldReceive('create')->once();

        // Through the domain model, as the inline pipeline did.
        $domain = Mockery::mock(\App\Models\Domain::class)->makePartial();
        $domain->shouldReceive('projectDomain')->once()->andReturn($domainProject);

        (new TemplateDeployMechanics($this->projectFor($user)))->publishDomain($domain);

        $this->addToAssertionCount(1);
    }

    public function test_the_custom_env_hint_is_only_given_when_env_vars_were_used(): void
    {
        $plain = new TemplateDeployMechanics($this->projectFor($this->makeUser('alice')));
        $this->assertNull($plain->customEnvFailureHint());

        $withEnv = new TemplateDeployMechanics(
            $this->projectFor($this->makeUser('bob', ['used_custom_env_vars' => true]))
        );
        $this->assertStringContainsString(
            'custom environment variables',
            (string) $withEnv->customEnvFailureHint()
        );
    }

    public function test_persisting_success_and_partial_success_record_the_status(): void
    {
        $user = $this->makeUser('alice');
        $mechanics = new TemplateDeployMechanics($this->projectFor($user));

        $mechanics->persistPartialSuccess(['nothing answered on 3000']);
        $stored = ModelsUser::findByUsername('alice')->getDetails();
        $this->assertSame('partial', $stored['deployment_status']);
        $this->assertSame(['nothing answered on 3000'], $stored['deployment_warnings']);

        $mechanics->persistSuccess();
        $this->assertSame('success', ModelsUser::findByUsername('alice')->getDetails()['deployment_status']);
    }

    /** The rebuild paths belong to DinD; a template project says so rather than half-running them. */
    public function test_the_rebuild_paths_are_refused_for_a_template_project(): void
    {
        $mechanics = new TemplateDeployMechanics($this->projectFor($this->makeUser('alice')));

        $calls = [
            'syncHostingForCheckoutRebuild' => fn () => $mechanics->syncHostingForCheckoutRebuild(false),
            'syncHostingForSourceRebuild' => fn () => $mechanics->syncHostingForSourceRebuild(null),
            'ingestForWipeRebuild' => fn () => $mechanics->ingestForWipeRebuild(null),
            'reprepareApplicationFromCheckout' => fn () => $mechanics->reprepareApplicationFromCheckout(),
            'ingestArchive' => fn () => $mechanics->ingestArchive('/tmp/app.zip'),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} should have been refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('requires a DinD project', $e->getMessage());
            }
        }
    }
}
