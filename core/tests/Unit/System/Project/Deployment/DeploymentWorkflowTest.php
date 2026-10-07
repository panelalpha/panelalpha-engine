<?php

namespace Tests\Unit\System\Project\Deployment;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeployableDindProject;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Deployment\FailureRetention;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use Tests\TestCase;

class DeploymentWorkflowTest extends TestCase
{
    private string $coreAppRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreAppRoot = dirname(__DIR__, 5) . '/app';
    }

    public function test_dind_wires_deployment_orchestrator(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Dind.php');
        $this->assertStringContainsString('implements DeployableDindProject', $source);
        $this->assertStringContainsString('function deployment(): DeploymentWorkflow', $source);
    }

    /**
     * The workflow is shared with the template path now, so the "keep the
     * project" rule lives in the disposition it defaults to rather than in
     * the workflow body.
     */
    public function test_workflow_source_never_deletes_project_on_failure(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DeploymentWorkflow.php');
        $this->assertStringNotContainsString('UserAccountDeletion', $source);
        $this->assertStringNotContainsString('destroy()', $source);
        $this->assertStringContainsString('new RetainProject()', $source);

        $retain = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/RetainProject.php');
        $this->assertStringContainsString('FailureRetention::', $retain);
        $this->assertStringNotContainsString('destroy()', $retain);
    }

    public function test_the_template_path_rolls_back_instead_of_retaining(): void
    {
        $rollback = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/RollBackProject.php');
        $this->assertStringContainsString('implements FailureDisposition', $rollback);
        $this->assertStringContainsString('->project()->destroy()', $rollback);
    }

    public function test_workflow_does_not_default_to_lib_user_deploy_mechanics(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DeploymentWorkflow.php');
        $this->assertStringNotContainsString('new LibUserDeployMechanics', $source);
    }

    public function test_failure_retention_persists_failed_status_without_delete(): void
    {
        $retentionSource = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/FailureRetention.php');
        $this->assertStringNotContainsString('UserAccountDeletion', $retentionSource);
        $this->assertStringContainsString("'deployment_status' => 'failed'", $retentionSource);
    }

    public function test_run_retains_project_when_prepare_throws(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->prepareException = new \RuntimeException('prepare blew up');

        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->run();
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('deploy_failed', $e->problems[0]['code'] ?? null);
        }

        $this->assertSame('failed', $model->getDetails()['deployment_status'] ?? null);
        $this->assertStringContainsString('prepare blew up', (string) ($model->getDetails()['error'] ?? ''));
        $this->assertFalse($mechanics->deletedProject);
    }

    public function test_run_retains_project_when_app_start_fails(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'build failed'];

        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->run();
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('app_did_not_start', $e->problems[0]['code'] ?? null);
        }

        $this->assertSame('failed', $model->getDetails()['deployment_status'] ?? null);
        $this->assertFalse($mechanics->deletedProject);
    }

    public function test_run_stops_after_prepare_when_dind_waits_for_files(): void
    {
        $model = $this->dindModel();
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->dindWithoutGit = true;

        (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->run();

        $this->assertTrue($mechanics->publishedDomain);
        $this->assertFalse($mechanics->ingestedSource);
        $this->assertFalse($mechanics->startedApplication);
    }

    public function test_run_persists_success_when_start_succeeds(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->hasGit = true;

        (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->run();

        $this->assertSame('success', $model->getDetails()['deployment_status'] ?? null);
        $this->assertSame([], $model->getDetails()['deployment_warnings'] ?? null);
        $this->assertTrue($mechanics->ingestedSource);
        $this->assertTrue($mechanics->startedApplication);
    }

    /**
     * A healthy app deployed with env_vars, on a host with no public IPv4: the
     * public-name warning made it partial, and the customer was told the deploy
     * failed with custom variables and to retry without the ones it needs.
     */
    public function test_a_public_url_warning_alone_does_not_blame_the_env_vars(): void
    {
        foreach (['run', 'rebuildFromCheckout'] as $path) {
            $model = $this->dindModel(['deploy_strategy' => 'express']);
            $domain = new DomainModel();
            $domain->domain = 'alice.example.test';
            $mechanics = new RecordingDeployMechanics($model, $domain);
            $mechanics->hasGit = true;
            $mechanics->publicUrl = ['The application is deployed but not reachable from the internet.'];
            $mechanics->envHint = 'Deploy failed with custom environment variables.';

            $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);
            $path === 'run' ? $workflow->run() : $workflow->rebuildFromCheckout($this->silentDeployLogger());

            $this->assertSame('partial', $model->getDetails()['deployment_status'] ?? null, $path);
            $this->assertSame($mechanics->publicUrl, $model->getDetails()['deployment_warnings'], $path);
        }
    }

    /** A failed redeploy keeps the project's volumes; only a failed first deploy may drop them. */
    public function test_a_failed_start_removes_volumes_only_on_the_first_deploy(): void
    {
        foreach (['run' => true, 'rebuildFromCheckout' => false] as $path => $removesVolumes) {
            $model = $this->dindModel(['deploy_strategy' => 'compose']);
            $domain = new DomainModel();
            $domain->domain = 'alice.example.test';
            $mechanics = new RecordingDeployMechanics($model, $domain);
            $mechanics->hasGit = true;
            $mechanics->applicationRunning = true;
            $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'manifest unknown'];

            $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);
            try {
                $path === 'run' ? $workflow->run() : $workflow->rebuildFromCheckout($this->silentDeployLogger());
                $this->fail("Expected ProblemException from {$path}");
            } catch (ProblemException) {
            }

            $this->assertSame($removesVolumes, $mechanics->abortRemovedVolumes, $path);
        }
    }

    /** An app that is not serving may have been broken by them, and is still told so. */
    public function test_an_app_that_is_not_serving_still_gets_the_env_vars_hint(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->hasGit = true;
        $mechanics->serving = ['The application did not answer its health check.'];
        $mechanics->envHint = 'Deploy failed with custom environment variables.';

        (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->run();

        $this->assertContains($mechanics->envHint, $model->getDetails()['deployment_warnings']);
    }

    public function test_rebuild_from_source_syncs_ingests_and_starts_without_persisting_status(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->hasGit = true;
        $mechanics->applicationRunning = true;

        (new DeploymentWorkflow(new StubDindProject($model), $mechanics))
            ->rebuildFromSource($this->silentDeployLogger(), null);

        $this->assertTrue($mechanics->syncedSourceRebuild);
        $this->assertTrue($mechanics->ingestedWipeRebuild);
        $this->assertTrue($mechanics->startedApplication);
        $this->assertNull($model->getDetails()['deployment_status'] ?? null);
    }

    public function test_rebuild_from_checkout_logs_git_as_its_source_by_default(): void
    {
        $started = $this->rebuildFromCheckoutAndCollectStart(fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromCheckout($l));

        $this->assertSame('Deploy started (source: git)', $started);
    }

    public function test_rebuild_from_checkout_names_a_push_and_its_commit_in_the_deploy_log(): void
    {
        $commit = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

        $started = $this->rebuildFromCheckoutAndCollectStart(
            fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromCheckout($l, 'push', $commit),
        );

        $this->assertSame("Deploy started (source: push, commit: {$commit})", $started);
    }

    /** A failed release started nothing, so a git or push redeploy leaves the running app up. */
    public function test_a_failed_release_on_a_checkout_redeploy_does_not_tear_the_running_app_down(): void
    {
        $failures = [
            'release' => [
                'exit_code' => 3,
                'stdout' => 'migrate failed',
                'stderr' => "The Procfile release process exited with code 3; the new version was not started.\n",
                AppLauncher::RELEASE_FAILED => true,
                AppLauncher::RAN_BEFORE_RELEASE => true,
            ],
            'up' => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'build failed'],
        ];
        foreach ($failures as $case => $result) {
            $mechanics = new RecordingMechanics($this->dindModel(['deploy_strategy' => 'express']), $this->mainDomain());
            $mechanics->hasGit = true;
            $mechanics->startResult = $result;
            $messages = [];
            $logger = $this->silentDeployLogger();
            $logger->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

            $problem = [];
            try {
                DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition())->rebuildFromCheckout($logger);
                $this->fail("Expected ProblemException ({$case})");
            } catch (ProblemException $e) {
                $problem = $e->problems[0] ?? [];
            }
            $this->assertSame('app_did_not_start', $problem['code'] ?? null, $case);

            if ($case === 'release') {
                $this->assertSame(['syncCheckoutRebuild', 'reprepare', 'start', 'settle'], $mechanics->calls);
                $this->assertStringContainsString('The Procfile release process exited with code 3', (string) ($problem['message'] ?? ''));
                $this->assertContains('The release failed before the new version started; the running app was left as it was', $messages);
            } else {
                $this->assertSame(['syncCheckoutRebuild', 'reprepare', 'start', 'abort', 'settle'], $mechanics->calls);
            }
        }
    }

    /**
     * POST /rebuild and an archive deploy say what a failed release left behind, as
     * a pull does; "left as it was" only when a version was running before it.
     */
    public function test_a_failed_release_on_a_source_or_archive_deploy_says_what_is_still_running(): void
    {
        $left = 'The release failed before the new version started; the running app was left as it was';
        $none = 'The release failed before the new version started; no version of the app is running';
        $cases = [
            'rebuild, previous version running' => ['rebuild', true, $left],
            'rebuild, app stopped before the wipe' => ['rebuild', false, $none],
            'first archive deploy' => ['archive', false, $none],
            'archive, could not ask' => ['archive', null, 'The release failed before the new version started'],
        ];
        foreach ($cases as $case => [$path, $ranBefore, $expected]) {
            $mechanics = new RecordingMechanics($this->dindModel(['deploy_strategy' => 'express']), $this->mainDomain());
            $mechanics->startResult = [
                'exit_code' => 3,
                'stdout' => 'migrate failed',
                'stderr' => "The Procfile release process exited with code 3; the new version was not started.\n",
                AppLauncher::RELEASE_FAILED => true,
                AppLauncher::RAN_BEFORE_RELEASE => $ranBefore,
            ];
            $messages = [];
            $logger = $this->silentDeployLogger();
            $logger->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

            $workflow = DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition());
            try {
                $path === 'rebuild'
                    ? $workflow->rebuildFromSource($logger, null)
                    : $workflow->deployFromArchive($logger, '/project/app.zip');
                $this->fail("Expected ProblemException ({$case})");
            } catch (ProblemException $e) {
                $this->assertStringContainsString('Failed to start app', $e->getMessage(), $case);
            }

            $said = array_values(array_filter($messages, static fn (string $m): bool => str_starts_with($m, 'The release failed')));
            $this->assertSame([$expected], $said, $case);
            $this->assertNotContains('The previous version is still serving; nothing was torn down', $messages, $case);
            $this->assertNotContains('abort', $mechanics->calls, $case);
        }

        // Nothing marked as a failed release says nothing about one.
        $mechanics = new RecordingMechanics($this->dindModel(['deploy_strategy' => 'express']), $this->mainDomain());
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'build failed'];
        $messages = [];
        $logger = $this->silentDeployLogger();
        $logger->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        try {
            DeploymentWorkflow::forMechanics($mechanics, new RecordingDisposition())->deployFromArchive($logger, '/project/app.zip');
            $this->fail('Expected ProblemException');
        } catch (ProblemException) {
        }
        $this->assertSame([], array_filter($messages, static fn (string $m): bool => str_starts_with($m, 'The release failed')));
    }

    private function mainDomain(): DomainModel
    {
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        return $domain;
    }

    public function test_a_working_fallback_domain_is_said_in_the_deploy_log_without_a_partial(): void
    {
        // Online refused, panelalpha_direct resolves, and the deploy used
        // to finish clean with the refusal written nowhere a person looks.
        $model = $this->dindModel([
            'deploy_strategy' => 'static',
            'domain' => [
                'source' => 'panelalpha_direct',
                'publicly_resolvable' => true,
                'tls_terminated_at' => 'engine',
                'fallback_reason' => 'panelalpha_online: PanelAlpha Online create failed: Sites limit reached for this service (HTTP 200).',
            ],
        ]);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->hasGit = true;
        $mechanics->applicationRunning = true;

        $warned = [];
        $finished = [];
        $logger = $this->silentDeployLogger();
        $logger->method('warn')->willReturnCallback(function (string $message) use (&$warned): void {
            $warned[] = $message;
        });
        $logger->method('finish')->willReturnCallback(function (string $status) use (&$finished): void {
            $finished[] = $status;
        });

        (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->rebuildFromCheckout($logger);

        $this->assertCount(1, $warned);
        $this->assertStringContainsString('Sites limit reached for this service', $warned[0]);
        $this->assertStringContainsString('panelalpha_direct', $warned[0]);
        $this->assertSame([DeployLogger::STATUS_SUCCESS], $finished);
    }

    /**
     * @param callable(DeploymentWorkflow, DeployLogger): void $run
     */
    private function rebuildFromCheckoutAndCollectStart(callable $run): string
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->hasGit = true;
        $mechanics->applicationRunning = true;

        $messages = [];
        $logger = $this->silentDeployLogger();
        $logger->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $run(new DeploymentWorkflow(new StubDindProject($model), $mechanics), $logger);

        $this->assertTrue($mechanics->startedApplication);
        $started = array_values(array_filter($messages, static fn (string $m): bool => str_starts_with($m, 'Deploy started')));
        $this->assertCount(1, $started);

        return $started[0];
    }

    public function test_rebuild_from_source_passes_zip_and_names_an_unrecognised_start_failure(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'build failed'];

        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->rebuildFromSource($this->silentDeployLogger(), '/tmp/app.zip');
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('app_did_not_start', $e->problems[0]['code']);
            $this->assertStringContainsString('Failed to start app', $e->getMessage());
        }

        $this->assertSame('/tmp/app.zip', $mechanics->sourceRebuildZipPath);
        $this->assertSame('/tmp/app.zip', $mechanics->wipeRebuildZipPath);
        $this->assertSame('failed', $model->getDetails()['deployment_status'] ?? null);
        $this->assertStringContainsString('Failed to start app', (string) ($model->getDetails()['error'] ?? ''));
        $this->assertFalse($mechanics->deletedProject);
    }

    public function test_a_failed_rebuild_of_a_deployed_project_is_recorded_and_stays_upgrading(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php', 'deployment_status' => 'partial']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->wipeRebuildException = new \RuntimeException("fatal: Remote branch nope not found in upstream origin");
        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->rebuildFromSource($this->silentDeployLogger(), null);
            $this->fail('Expected Exception');
        } catch (\RuntimeException $e) {
        }

        $this->assertSame('failed', $model->getDetails()['deployment_status'] ?? null);
        $this->assertNotSame('', (string) ($model->getDetails()['error'] ?? ''));
        $this->assertTrue($model->hasDeployedBefore());
        $this->assertSame(
            \App\Lib\Deploy\Platform\PlatformStage::UPGRADE,
            \App\Lib\Deploy\Platform\PlatformStage::phaseFor($model->getDeploymentStatus(), $model->hasDeployedBefore())
        );
    }

    public function test_a_failed_first_deploy_does_not_count_as_deployed_before(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->wipeRebuildException = new \RuntimeException('clone failed');

        try {
            (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->rebuildFromSource($this->silentDeployLogger(), null);
            $this->fail('Expected Exception');
        } catch (\RuntimeException $e) {
        }

        $this->assertSame('failed', $model->getDetails()['deployment_status'] ?? null);
        $this->assertFalse($model->hasDeployedBefore());
    }

    public function test_a_refused_archive_leaves_the_deploy_status_alone(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php', 'deployment_status' => 'success']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->archiveException = new \InvalidArgumentException('zip_path must be inside the account home');
        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->deployFromArchive($this->silentDeployLogger(), '/etc/passwd');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
        }

        $this->assertSame('success', $model->getDetails()['deployment_status'] ?? null);
        $this->assertFalse($mechanics->startedApplication);
    }

    public function test_deploy_from_archive_ingests_and_starts_without_wiping_or_persisting_status(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        $workflow->deployFromArchive($this->silentDeployLogger(), '/project/app.zip');

        $this->assertSame('/project/app.zip', $mechanics->archiveZipPath);
        $this->assertTrue($mechanics->startedApplication);
        $this->assertFalse($mechanics->syncedSourceRebuild, 'the archive sits in ~/project, so nothing may wipe it first');
        $this->assertNull($model->getDetails()['deployment_status'] ?? null);
    }

    public function test_deploy_from_archive_names_an_unrecognised_start_failure(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'env file .env not found'];
        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->deployFromArchive($this->silentDeployLogger(), '/project/app.zip');
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('app_did_not_start', $e->problems[0]['code']);
            $this->assertStringContainsString('Failed to start app', $e->getMessage());
        }

        $this->assertSame('failed', $model->getDetails()['deployment_status'] ?? null);
        $this->assertFalse($mechanics->deletedProject);
    }

    /**
     * The rebuild answered `rebuild_failed` for a failure the same deploy log
     * explained as a base image: the rule was looked for in the sentence the
     * explainer had already written, which no rule matches.
     */
    public function test_a_failed_rebuild_carries_the_rule_that_explained_it(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'dockerfile']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => "Image project-app Building\n"
            . 'failed to solve: example.org/base:1: failed to resolve source metadata for example.org/base:1: '
            . 'example.org/base:1: not found'];
        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->rebuildFromSource($this->silentDeployLogger(), null);
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertSame('base-image-unavailable', $e->problems[0]['code']);
            $this->assertStringContainsString('could not be downloaded', $e->getMessage());
        }
    }

    /**
     * A new version that never became healthy did not replace the
     * old one, so tearing the app down would stop the version still serving.
     */
    public function test_a_redeploy_that_kept_the_previous_version_does_not_tear_it_down(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->hasGit = true;
        $mechanics->startResult = [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'The new version did not become healthy: it exited with code 1. The previous version is still serving.',
            ZeroDowntimeRedeploy::PREVIOUS_KEPT => true,
        ];

        try {
            (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->rebuildFromCheckout($this->silentDeployLogger());
            $this->fail('Expected ProblemException');
        } catch (ProblemException $e) {
            $this->assertStringContainsString('previous version is still serving', $e->getMessage());
        }

        $this->assertFalse($mechanics->aborted);
        $this->assertSame(1, $mechanics->settled);
    }

    /** A rebuild or an archive deploy says the old version kept serving, as a pull does. */
    public function test_a_source_redeploy_that_kept_the_previous_version_says_so(): void
    {
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $paths = [
            'source' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromSource($l, null),
            'archive' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->deployFromArchive($l, '/project/app.zip'),
        ];
        foreach ($paths as $name => $run) {
            foreach ([true, false] as $kept) {
                $mechanics = new RecordingDeployMechanics($this->dindModel(['deploy_strategy' => 'dockerfile']), $domain);
                $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'failed to solve: no FROM'];
                if ($kept) {
                    $mechanics->startResult[ZeroDowntimeRedeploy::PREVIOUS_KEPT] = true;
                }
                $messages = [];
                $logger = $this->silentDeployLogger();
                $logger->method('info')->willReturnCallback(function (string $message) use (&$messages): void {
                    $messages[] = $message;
                });

                try {
                    $run(new DeploymentWorkflow(new StubDindProject($mechanics->user()), $mechanics), $logger);
                    $this->fail("{$name}: expected the deploy to fail");
                } catch (ProblemException) {
                }

                $this->assertSame(
                    $kept,
                    in_array('The previous version is still serving; nothing was torn down', $messages, true),
                    "{$name}, kept " . var_export($kept, true)
                );
                $this->assertFalse($mechanics->aborted, $name);
            }
        }
    }

    /** A new version refused for an empty page did start; the failure says what it served, not that it did not start. */
    public function test_a_new_version_refused_for_an_empty_page_is_not_called_a_failed_start(): void
    {
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $refusal = ZeroDowntimeRedeploy::refusal('it answers HTTP 200 with an empty page');
        $paths = [
            'checkout' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromCheckout($l),
            'source' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->rebuildFromSource($l, null),
            'archive' => fn (DeploymentWorkflow $w, DeployLogger $l) => $w->deployFromArchive($l, '/project/app.zip'),
        ];
        foreach ($paths as $name => $run) {
            $mechanics = new RecordingDeployMechanics($this->dindModel(['deploy_strategy' => 'dockerfile']), $domain);
            $mechanics->hasGit = true;
            $mechanics->startResult = ZeroDowntimeRedeploy::previousKept('', $refusal, 1);

            try {
                $run(new DeploymentWorkflow(new StubDindProject($mechanics->user()), $mechanics), $this->silentDeployLogger());
                $this->fail("{$name}: expected the deploy to fail");
            } catch (ProblemException $e) {
                $this->assertSame('new-version-empty-page', $e->problems[0]['code'], $name);
                $this->assertSame($refusal, $e->problems[0]['message'], $name);
            }
            $this->assertFalse($mechanics->aborted, $name);
        }
        $this->assertStringContainsString('previous version is still serving', $refusal);
        $this->assertStringContainsString('answers 204', $refusal);
    }

    public function test_a_failed_checkout_redeploy_without_a_kept_version_still_tears_down(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'build failed'];

        try {
            (new DeploymentWorkflow(new StubDindProject($model), $mechanics))->rebuildFromCheckout($this->silentDeployLogger());
            $this->fail('Expected ProblemException');
        } catch (ProblemException) {
        }

        $this->assertTrue($mechanics->aborted);
    }

    /** Every redeploy path settles what it put aside, whatever it ended in. */
    public function test_every_redeploy_settles_what_it_kept_aside(): void
    {
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';
        $paths = [
            'checkout' => fn (DeploymentWorkflow $w) => $w->rebuildFromCheckout($this->silentDeployLogger()),
            'source' => fn (DeploymentWorkflow $w) => $w->rebuildFromSource($this->silentDeployLogger(), null),
            'archive' => fn (DeploymentWorkflow $w) => $w->deployFromArchive($this->silentDeployLogger(), '/project/app.zip'),
        ];
        foreach ($paths as $name => $run) {
            foreach ([0, 1] as $exit) {
                $mechanics = new RecordingDeployMechanics($this->dindModel(['deploy_strategy' => 'express']), $domain);
                $mechanics->startResult = ['exit_code' => $exit, 'stdout' => '', 'stderr' => $exit === 0 ? '' : 'build failed'];
                try {
                    $run(new DeploymentWorkflow(new StubDindProject($mechanics->user()), $mechanics));
                } catch (\Exception) {
                }
                $this->assertSame(1, $mechanics->settled, "{$name}, exit {$exit}");
                // A failed redeploy may put the project back as it was; a successful one never.
                $this->assertSame([$exit === 0], $mechanics->settledAsSucceeded, "{$name}, exit {$exit}");
            }
        }
    }

    public function test_workflow_exposes_rebuild_from_source(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DeploymentWorkflow.php');
        $this->assertStringContainsString('function rebuildFromSource(', $source);
        $this->assertDoesNotMatchRegularExpression('/\bclass EnvironmentRebuild\b/', $source);
        $this->assertFileDoesNotExist($this->coreAppRoot . '/System/Project/EnvironmentRebuild.php');
    }

    /**
     * DeployLogger with no filesystem I/O — enough for rebuildFromSource orchestration tests.
     */
    private function silentDeployLogger(): DeployLogger
    {
        $logger = $this->createStub(DeployLogger::class);
        $logger->method('readLatest')->willReturn(['stage' => null]);
        $logger->method('isRunning')->willReturn(false);

        $lock = new \App\Lib\Deploy\DeployLog\DeployLock(
            new \App\Lib\Deploy\DeployLog\DeployLogPaths('workflow-test'),
        );
        (new \ReflectionClass(DeployLogger::class))
            ->getProperty('lock')
            ->setValue($logger, $lock);

        return $logger;
    }

    private function dindModel(array $details = []): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->template = 'dind';
        $model->domain = 'alice.example.test';
        $model->setDetails(array_merge([
            'UID' => 1000,
            'GID' => 1000,
            'template' => 'dind',
        ], $details));

        return $model;
    }

}

/**
 * @internal
 */
final class StubDindProject implements DeployableDindProject
{
    public function __construct(
        private ModelsUser $userModel,
    ) {
    }

    public function userModel(): ModelsUser
    {
        return $this->userModel;
    }
}

/**
 * @internal
 */
final class RecordingDeployMechanics implements DeployMechanics
{
    public ?\Throwable $prepareException = null;
    public ?\Throwable $wipeRebuildException = null;
    public ?\Throwable $archiveException = null;

    /** @var array{exit_code: int, stdout: string, stderr: string} */
    public array $startResult = ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];

    public bool $dindWithoutGit = false;
    public bool $hasGit = false;
    public bool $publishedDomain = false;
    public bool $ingestedSource = false;
    public bool $startedApplication = false;
    public bool $deletedProject = false;
    public bool $applicationRunning = false;
    public bool $syncedSourceRebuild = false;
    public ?bool $abortRemovedVolumes = null;
    public bool $ingestedWipeRebuild = false;
    public ?string $sourceRebuildZipPath = null;
    public ?string $wipeRebuildZipPath = null;
    public ?string $archiveZipPath = null;
    /** @var list<string> */
    public array $serving = [];
    /** @var list<string> */
    public array $publicUrl = [];
    public ?string $envHint = null;
    public int $settled = 0;
    /** @var list<bool> */
    public array $settledAsSucceeded = [];
    public bool $aborted = false;

    public function __construct(
        private ModelsUser $userModel,
        private DomainModel $domain,
    ) {
    }

    public function user(): ModelsUser
    {
        return $this->userModel;
    }

    public function requireMainDomain(): DomainModel
    {
        return $this->domain;
    }

    public function hasGitProject(): bool
    {
        return $this->hasGit;
    }

    public function isDindWithoutGit(): bool
    {
        return $this->dindWithoutGit;
    }

    public function prepareHostingEnvironment(): void
    {
        if ($this->prepareException !== null) {
            throw $this->prepareException;
        }
    }

    public function ingestApplicationSource(): void
    {
        $this->ingestedSource = true;
    }

    public function isApplicationEnvironmentRunning(): bool
    {
        return $this->applicationRunning;
    }

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void
    {
    }

    public function syncHostingForSourceRebuild(?string $zipPath): void
    {
        $this->syncedSourceRebuild = true;
        $this->sourceRebuildZipPath = $zipPath;
    }

    public function ingestForWipeRebuild(?string $zipPath): void
    {
        $this->ingestedWipeRebuild = true;
        $this->wipeRebuildZipPath = $zipPath;
        if ($this->wipeRebuildException !== null) {
            throw $this->wipeRebuildException;
        }
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->archiveZipPath = $zipPath;
        if ($this->archiveException !== null) {
            throw $this->archiveException;
        }
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->ingestedSource = true;
    }

    public function publishDomain(DomainModel $domain): void
    {
        $this->publishedDomain = true;
    }

    public function startApplication(): array
    {
        $this->startedApplication = true;

        return $this->startResult;
    }

    public function abortPartialDeploy(bool $removeVolumes = false): void
    {
        $this->abortRemovedVolumes = $removeVolumes;
        $this->aborted = true;
    }

    public function settleRedeploy(bool $succeeded): void
    {
        $this->settled++;
        $this->settledAsSucceeded[] = $succeeded;
    }

    public function servingWarnings(): array
    {
        return $this->serving;
    }

    public function publicUrlWarnings(DomainModel $domain): array
    {
        return $this->publicUrl;
    }

    public function customEnvFailureHint(): ?string
    {
        return $this->envHint;
    }

    public function persistPartialSuccess(array $warnings): void
    {
        $this->userModel->setDetails([
            'deployment_warnings' => $warnings,
            'deployment_status' => 'partial',
        ]);
    }

    public function persistSuccess(): void
    {
        $this->userModel->setDetails(['deployment_status' => 'success', 'deployment_warnings' => []]);
    }
}
