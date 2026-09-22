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
use PHPUnit\Framework\TestCase;

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

    public function test_workflow_source_never_deletes_project_on_failure(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DeploymentWorkflow.php');
        $this->assertStringNotContainsString('UserAccountDeletion', $source);
        $this->assertStringContainsString('FailureRetention::', $source);
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

    public function test_rebuild_from_source_passes_zip_and_throws_plain_exception_on_start_failure(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'express']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'build failed'];

        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->rebuildFromSource($this->silentDeployLogger(), '/tmp/app.zip');
            $this->fail('Expected Exception');
        } catch (ProblemException $e) {
            $this->fail('rebuildFromSource must not throw ProblemException: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('Failed to start app', $e->getMessage());
        }

        $this->assertSame('/tmp/app.zip', $mechanics->sourceRebuildZipPath);
        $this->assertSame('/tmp/app.zip', $mechanics->wipeRebuildZipPath);
        $this->assertNull($model->getDetails()['deployment_status'] ?? null);
        $this->assertFalse($mechanics->deletedProject);
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

    public function test_deploy_from_archive_throws_plain_exception_on_start_failure(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'php']);
        $domain = new DomainModel();
        $domain->domain = 'alice.example.test';

        $mechanics = new RecordingDeployMechanics($model, $domain);
        $mechanics->startResult = ['exit_code' => 1, 'stdout' => '', 'stderr' => 'env file .env not found'];
        $workflow = new DeploymentWorkflow(new StubDindProject($model), $mechanics);

        try {
            $workflow->deployFromArchive($this->silentDeployLogger(), '/project/app.zip');
            $this->fail('Expected Exception');
        } catch (ProblemException $e) {
            $this->fail('deployFromArchive must not throw ProblemException: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->assertStringContainsString('Failed to start app', $e->getMessage());
        }

        $this->assertFalse($mechanics->deletedProject);
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
    public bool $ingestedWipeRebuild = false;
    public ?string $sourceRebuildZipPath = null;
    public ?string $wipeRebuildZipPath = null;
    public ?string $archiveZipPath = null;

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
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->archiveZipPath = $zipPath;
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

    public function abortPartialDeploy(): void
    {
    }

    public function servingWarnings(): array
    {
        return [];
    }

    public function publicUrlWarnings(DomainModel $domain): array
    {
        return [];
    }

    public function customEnvFailureHint(): ?string
    {
        return null;
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
