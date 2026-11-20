<?php

namespace Tests\Integration\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeployableDindProject;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Dind;
use Mockery;
use Tests\TestCase;

/** The workflow's choice of mechanics, its cancel hook and how it words a failed start. */
class DeploymentWorkflowEdgesTest extends TestCase
{
    public function test_a_project_that_is_not_a_dind_runtime_needs_its_mechanics_given(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Default deploy mechanics require a DinD runtime project.');

        (new DeploymentWorkflow(Mockery::mock(DeployableDindProject::class)))->run();
    }

    public function test_a_dind_runtime_is_deployed_with_the_dind_mechanics(): void
    {
        $user = Mockery::mock(ModelsUser::class)->makePartial();
        $user->shouldReceive('getMainDomain')->andReturn(null);
        $user->username = 'nodomain';
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userModel')->andReturn($user);

        $this->expectExceptionMessage("Project 'nodomain' has no main domain.");

        (new DeploymentWorkflow($dind))->run();
    }

    public function test_a_cancel_skips_the_retention_hook_a_disposition_keeps_for_failures(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->prepareException = new DeployCancelledException('Deployment cancelled by user');
        $disposition = new RecordingDisposition;
        $disposition->hookRunsOnCancel = false;
        $hooked = [];

        try {
            DeploymentWorkflow::forMechanics($mechanics, $disposition)->run(
                $this->logger(),
                static function () use (&$hooked): void {
                    $hooked[] = 'hook';
                },
            );
            $this->fail('Expected the deploy to end cancelled');
        } catch (ProblemException $e) {
            $this->assertSame('deploy_cancelled', $e->problems[0]['code'] ?? null);
        }

        $this->assertSame([], $hooked);
        $this->assertSame(['cancelled'], $disposition->calls);
    }

    public function test_a_cancel_runs_the_retention_hook_when_the_disposition_wants_it(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->prepareException = new DeployCancelledException('Deployment cancelled by user');
        $hooked = [];

        try {
            DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition)->run(
                $this->logger(),
                static function () use (&$hooked): void {
                    $hooked[] = 'hook';
                },
            );
            $this->fail('Expected the deploy to end cancelled');
        } catch (ProblemException) {
        }

        $this->assertSame(['hook'], $hooked);
    }

    public function test_a_noise_only_start_failure_is_reported_as_printed_when_the_explainer_is_not_asked(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => "npm warn deprecated left-pad@1.0.0\n"];

        $message = $this->failedStart(DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition, explainNoiseOnlyOutput: false));

        $this->assertSame('Failed to start app: npm warn deprecated left-pad@1.0.0', $message);
    }

    public function test_a_new_version_refused_for_an_empty_page_is_said_without_the_start_prefix(): void
    {
        $sentence = DeployFailureExplainer::EMPTY_NEW_VERSION.', so the site was not moved to it.';
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => $sentence];

        $message = $this->failedStart(DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition), 'new-version-empty-page');

        $this->assertSame($sentence, $message);
    }

    private function failedStart(DeploymentWorkflow $workflow, string $code = 'app_did_not_start'): string
    {
        try {
            $workflow->run();
        } catch (ProblemException $e) {
            $this->assertSame($code, $e->problems[0]['code'] ?? null);

            return $e->getMessage();
        }
        $this->fail('Expected the start to fail');
    }

    private function logger(): DeployLogger
    {
        $logger = $this->createStub(DeployLogger::class);
        $logger->method('readLatest')->willReturn(['stage' => null]);
        $logger->method('isRunning')->willReturn(false);
        (new \ReflectionProperty(DeployLogger::class, 'lock'))->setValue($logger, new DeployLock(new DeployLogPaths('workflow-edges')));

        return $logger;
    }

    private function model(): ModelsUser
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->domain = 'alice.example.test';
        $model->setDetails(['UID' => 1000, 'GID' => 1000, 'template' => 'dind']);

        return $model;
    }

    private function domain(): DomainModel
    {
        $domain = new DomainModel;
        $domain->domain = 'alice.example.test';

        return $domain;
    }
}
