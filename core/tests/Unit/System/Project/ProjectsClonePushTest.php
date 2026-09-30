<?php

namespace Tests\Unit\System\Project;

use PHPUnit\Framework\TestCase;

class ProjectsClonePushTest extends TestCase
{
    private string $coreAppRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreAppRoot = dirname(__DIR__, 4) . '/app';
    }

    public function test_projects_module_uses_project_factory_and_no_implementation_instanceof(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Projects.php');
        $this->assertStringContainsString('project($', $source);
        $this->assertStringNotContainsString('instanceof PhpHosting', $source);
        $this->assertStringNotContainsString('instanceof PhpFpm', $source);
        $this->assertStringNotContainsString('use App\System\Account', $source);
        $this->assertStringNotContainsString('new Account', $source);
        $this->assertStringContainsString('createFromTemplate()', $source);
        $this->assertStringContainsString('waitForAllRunning()', $source);
        $this->assertStringNotContainsString('CopyHosting', $source);
    }

    public function test_system_projects_clone_and_copy_retain_destination_on_failure(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Projects.php');
        $this->assertStringNotContainsString('UserAccountDeletion', $source);
        $this->assertStringContainsString('retainDestinationAfterFailure', $source);
        $this->assertStringNotContainsString('connect()', $source);
        $this->assertStringContainsString('function clone(', $source);
        $this->assertStringContainsString('function copy(', $source);
        $this->assertStringNotContainsString('use App\System\Account', $source);
        $this->assertStringNotContainsString('new Account', $source);
    }

    public function test_system_projects_push_to_app_marks_failure_without_full_delete(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Projects.php');
        $this->assertStringNotContainsString('UserAccountDeletion', $source);
        $this->assertStringContainsString('markPushFailed', $source);
        $this->assertStringContainsString('function pushToApp(', $source);
        $this->assertStringNotContainsString('connect()', $source);
        $this->assertStringNotContainsString('CopyHosting', $source);
    }

    public function test_production_clone_staging_and_push_callers_use_system_projects(): void
    {
        $workerPaths = [
            'Http/Controllers/UserController.php',
            'Jobs/CreateStaging.php',
            'Jobs/PushState.php',
            'Console/Commands/Projects/ProjectStagingCommand.php',
            'Console/Commands/Projects/ProjectPushCommand.php',
        ];

        foreach ($workerPaths as $path) {
            $source = file_get_contents($this->coreAppRoot . '/' . $path);
            $this->assertStringNotContainsString('App\\Lib\\Copy\\AccountClone', $source, $path);
            $this->assertStringNotContainsString('App\\Lib\\Copy\\Push', $source, $path);
            $this->assertStringNotContainsString('new Push()', $source, $path);
            $this->assertStringContainsString('projects()->', $source, $path);
        }

        $this->assertStringContainsString("projects()->clone(", file_get_contents($this->coreAppRoot . '/Http/Controllers/UserController.php'));
        $this->assertStringContainsString("projects()->copy(", file_get_contents($this->coreAppRoot . '/Jobs/CreateStaging.php'));
        $this->assertStringContainsString("projects()->pushToApp(", file_get_contents($this->coreAppRoot . '/Jobs/PushState.php'));

        $stagingHttp = file_get_contents($this->coreAppRoot . '/Http/Controllers/User/StagingController.php');
        $this->assertStringNotContainsString('App\\Lib\\Copy', $stagingHttp);
        $this->assertStringContainsString('CreateStaging::dispatch', $stagingHttp);
        $this->assertStringContainsString('PushState::dispatch', $stagingHttp);
    }

    public function test_clone_or_copy_applies_deploy_snapshot_before_volumes_and_start(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Projects.php');
        $this->assertIsString($source);

        $copyPos = strpos($source, 'function cloneOrCopy(');
        $this->assertNotFalse($copyPos);
        $pushPos = strpos($source, 'function templateKey(');
        $this->assertNotFalse($pushPos);
        $copyBlock = substr($source, $copyPos, $pushPos - $copyPos);

        $snapshotPos = strpos($copyBlock, 'applyDeploySnapshotFrom');
        $volumePos = strpos($copyBlock, 'copyVolumeDataFrom');
        $proxyPos = strpos($copyBlock, 'syncGeneratedProxyRules');
        $domainPos = strpos($copyBlock, 'domain($domainModel)->create()');
        $startPos = strpos($copyBlock, 'startUserApp');

        $this->assertNotFalse($snapshotPos, 'cloneOrCopy must copy the frozen deploy snapshot');
        $this->assertNotFalse($volumePos);
        $this->assertNotFalse($proxyPos, 'cloneOrCopy must create dest proxy rules');
        $this->assertNotFalse($domainPos);
        $this->assertNotFalse($startPos);
        $this->assertLessThan($volumePos, $snapshotPos, 'snapshot must land before volume copy');
        $this->assertLessThan($startPos, $snapshotPos, 'snapshot must land before startUserApp');
        $this->assertLessThan($domainPos, $proxyPos, 'proxy rules must exist before vhost create');
        $this->assertLessThan($startPos, $proxyPos);

        $pushFn = strpos($source, 'function pushToApp(');
        $this->assertNotFalse($pushFn);
        $cloneFn = strpos($source, 'function cloneOrCopy(');
        $pushBlock = substr($source, $pushFn, $cloneFn - $pushFn);
        $this->assertStringContainsString('applyDeploySnapshotFrom', $pushBlock);
    }

    public function test_production_validation_uses_system_projects_not_lib_copy(): void
    {
        $paths = [
            'Http/Requests/ProjectStagingRequest.php',
            'Http/Requests/ProjectPushRequest.php',
            'Http/Controllers/User/StagingController.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($this->coreAppRoot . '/' . $path);
            $this->assertStringNotContainsString('App\\Lib\\Copy', $source, $path);
            $this->assertStringNotContainsString('ProjectCopyGuards', $source, $path);
            $this->assertStringContainsString('Projects', $source, $path);
        }
    }
}
