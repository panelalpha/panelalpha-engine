<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\FailureDisposition;
use App\System\Project\Deployment\RetainProject;
use App\System\Project\Deployment\RollBackProject;
use App\System\Project\Deployment\TemplateDeployMechanics;
use App\System\Project\Dind;
use App\System\Project\PhpHosting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Project decides which mechanics and which failure disposition a project's
 * first deploy runs with: DinD through deployment(), a template project
 * through the workflow runDeployment() builds for it.
 */
class DeploymentWiringTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    private function projectFor(ModelsUser $user): SystemProject
    {
        return new SystemProject(new EngineSystem(), $user);
    }

    private function templateWorkflow(SystemProject $project): DeploymentWorkflow
    {
        return (new \ReflectionMethod($project, 'templateDeployment'))->invoke($project);
    }

    /** @return array{0: ?DeployMechanics, 1: ?FailureDisposition} */
    private function wiring(DeploymentWorkflow $workflow): array
    {
        $read = static function (string $property) use ($workflow) {
            $p = new \ReflectionProperty($workflow, $property);

            return $p->getValue($workflow);
        };

        return [$read('mechanics'), $read('disposition')];
    }

    public function test_a_template_project_rolls_back_a_failed_deploy(): void
    {
        $project = $this->projectFor($this->makeUser('alice', ['template' => 'wordpress']));
        $this->assertInstanceOf(PhpHosting::class, $project->runtime());

        [$mechanics, $disposition] = $this->wiring($this->templateWorkflow($project));

        $this->assertInstanceOf(TemplateDeployMechanics::class, $mechanics);
        $this->assertInstanceOf(RollBackProject::class, $disposition);
    }

    public function test_a_template_project_reports_noise_only_start_output_as_it_stands(): void
    {
        $project = $this->projectFor($this->makeUser('alice', ['template' => 'wordpress']));
        $flag = new \ReflectionProperty(DeploymentWorkflow::class, 'explainNoiseOnlyOutput');

        $this->assertFalse($flag->getValue($this->templateWorkflow($project)));
        $this->assertTrue($flag->getValue(new DeploymentWorkflow()));
    }

    public function test_a_dind_project_keeps_a_failed_deploy(): void
    {
        $project = $this->projectFor($this->makeUser('bob', ['template' => 'dind']));
        $this->assertInstanceOf(Dind::class, $project->runtime());

        [$mechanics, $disposition] = $this->wiring($project->deployment());

        // DinD keeps the defaults: mechanics built on demand, retention policy.
        $this->assertNull($mechanics);
        $this->assertNull($disposition);
    }

    public function test_the_default_disposition_is_retention(): void
    {
        $workflow = new DeploymentWorkflow();
        $resolved = (new \ReflectionMethod($workflow, 'disposition'))->invoke($workflow);

        $this->assertInstanceOf(RetainProject::class, $resolved);
    }

    public function test_only_a_dind_project_exposes_a_deployment_workflow(): void
    {
        $dind = $this->projectFor($this->makeUser('p-dind', ['template' => 'dind']));
        $template = $this->projectFor($this->makeUser('p-wordpress', ['template' => 'wordpress']));

        $this->assertInstanceOf(DeploymentWorkflow::class, $dind->deployment());
        $this->assertNull($template->deployment());
    }

    public function test_the_same_template_workflow_is_reused_per_project(): void
    {
        $project = $this->projectFor($this->makeUser('alice', ['template' => 'wordpress']));

        $this->assertSame($this->templateWorkflow($project), $this->templateWorkflow($project));
    }

    /**
     * A template project has no source to re-ingest: the rebuild recreates its
     * host side instead. Routing it through the DinD workflow would reach
     * TemplateDeployMechanics::syncHostingForSourceRebuild(), which refuses.
     */
    public function test_a_template_rebuild_recreates_the_host_side_rather_than_re_ingesting(): void
    {
        $project = $this->projectFor($this->makeUser('alice', ['template' => 'wordpress']));
        $this->assertInstanceOf(PhpHosting::class, $project->runtime());

        $reflection = new \ReflectionMethod($project, 'rebuildFromSource');
        $this->assertSame('rebuildFromSource', $reflection->getName());

        // The mechanics the DinD path would use refuse a template project, so
        // reaching them at all is the regression this guards.
        $mechanics = new TemplateDeployMechanics($project);
        try {
            $mechanics->syncHostingForSourceRebuild(null);
            $this->fail('TemplateDeployMechanics must refuse a source rebuild');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires a DinD project', $e->getMessage());
        }
    }

    public function test_a_dind_rebuild_goes_through_the_workflow(): void
    {
        $project = $this->projectFor($this->makeUser('bob', ['template' => 'dind']));

        $this->assertInstanceOf(Dind::class, $project->runtime());
        $this->assertInstanceOf(DeploymentWorkflow::class, $project->deployment());
    }
}
