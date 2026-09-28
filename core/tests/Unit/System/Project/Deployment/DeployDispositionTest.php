<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\FailureDisposition;
use App\System\Project\Deployment\RetainProject;
use Tests\TestCase;

/**
 * The two deploy paths run the same workflow and differ only in what happens
 * to a project whose deploy did not finish. These pin that seam, and the step
 * order both paths share.
 */
class DeployDispositionTest extends TestCase
{
    private function model(string $template = 'dind'): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->template = $template;
        $model->domain = 'alice.example.test';
        $model->setDetails(['UID' => 1000, 'GID' => 1000]);

        return $model;
    }

    private function domain(): DomainModel
    {
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        return $domain;
    }

    private function workflow(RecordingMechanics $mechanics, RecordingDisposition $disposition): DeploymentWorkflow
    {
        return DeploymentWorkflow::forMechanics($mechanics, $disposition);
    }

    public function test_a_clean_run_walks_the_stages_in_order_and_disposes_of_nothing(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->hasGit = true;
        $disposition = new RecordingDisposition();

        $this->workflow($mechanics, $disposition)->run();

        $this->assertSame(
            ['prepare', 'ingest', 'publish', 'start', 'persistSuccess'],
            $mechanics->calls
        );
        $this->assertSame([], $disposition->calls);
    }

    public function test_a_dind_project_without_git_stops_after_publishing_the_domain(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->dindWithoutGit = true;
        $disposition = new RecordingDisposition();

        $this->workflow($mechanics, $disposition)->run();

        $this->assertSame(['prepare', 'publish'], $mechanics->calls);
        $this->assertSame([], $disposition->calls);
    }

    public function test_a_failed_prepare_is_handed_to_the_disposition(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->prepareException = new \RuntimeException('prepare blew up');
        $disposition = new RecordingDisposition();

        try {
            $this->workflow($mechanics, $disposition)->run();
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('deploy_failed', $e->problems[0]['code'] ?? null);
        }

        $this->assertSame(['failure'], $disposition->calls);
        $this->assertStringContainsString('prepare blew up', $disposition->lastMessage ?? '');
    }

    public function test_a_cancelled_deploy_is_handed_to_the_disposition(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->prepareException = new DeployCancelledException('operator cancelled');
        $disposition = new RecordingDisposition();

        try {
            $this->workflow($mechanics, $disposition)->run();
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('deploy_cancelled', $e->problems[0]['code'] ?? null);
        }

        $this->assertSame(['cancelled'], $disposition->calls);
    }

    public function test_an_app_that_does_not_start_is_handed_to_the_disposition(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->hasGit = true;
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'boom'];
        $disposition = new RecordingDisposition();

        try {
            $this->workflow($mechanics, $disposition)->run();
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('app_did_not_start', $e->problems[0]['code'] ?? null);
        }

        $this->assertContains('abort', $mechanics->calls);
        $this->assertSame(['failure'], $disposition->calls);
    }

    /**
     * The hook exists to preserve the deploy log tail, so it is skipped when
     * there is no logger — on cancel as well as on failure. That the hook runs
     * *before* the disposition is covered against a real logger in
     * {@see \Tests\Unit\Deploy\DeployPipelineRollbackTest}.
     */
    public function test_without_a_logger_the_hook_is_skipped_but_the_project_is_still_disposed_of(): void
    {
        foreach ([new \RuntimeException('boom'), new DeployCancelledException('cancelled')] as $thrown) {
            $mechanics = new RecordingMechanics($this->model(), $this->domain());
            $mechanics->prepareException = $thrown;
            $disposition = new RecordingDisposition();
            $order = [];

            try {
                $this->workflow($mechanics, $disposition)->run(null, function () use (&$order): void {
                    $order[] = 'hook';
                });
            } catch (ProblemException) {
                // expected
            }

            // The hook only fires when there is a logger to hand it.
            $this->assertSame([], $order, 'no logger means no hook');
            $this->assertNotSame([], $disposition->calls);
        }
    }

    public function test_warnings_from_serving_and_public_url_make_the_deploy_partial(): void
    {
        $model = $this->model();
        $mechanics = new RecordingMechanics($model, $this->domain());
        $mechanics->hasGit = true;
        $mechanics->serving = ['nothing answered on 3000'];
        $mechanics->publicUrl = ['certificate not trusted'];

        $this->workflow($mechanics, new RecordingDisposition())->run();

        $this->assertContains('persistPartial', $mechanics->calls);
        $this->assertNotContains('persistSuccess', $mechanics->calls);
        $this->assertSame(
            ['nothing answered on 3000', 'certificate not trusted'],
            $mechanics->partialWarnings
        );
    }

    public function test_the_custom_env_hint_is_appended_to_a_partial_deploy(): void
    {
        $mechanics = new RecordingMechanics($this->model(), $this->domain());
        $mechanics->hasGit = true;
        $mechanics->serving = ['nothing answered on 3000'];
        $mechanics->envHint = 'custom env vars were set';

        $this->workflow($mechanics, new RecordingDisposition())->run();

        $this->assertSame(
            ['nothing answered on 3000', 'custom env vars were set'],
            $mechanics->partialWarnings
        );
    }

    public function test_the_default_disposition_retains_the_project(): void
    {
        $workflow = new DeploymentWorkflow();
        $resolved = (new \ReflectionMethod($workflow, 'disposition'))->invoke($workflow);

        $this->assertInstanceOf(RetainProject::class, $resolved);
        $this->assertInstanceOf(FailureDisposition::class, $resolved);
    }
}
