<?php

namespace Tests\Unit\System\Project;

use PHPUnit\Framework\TestCase;

class ProjectsClonePushTest extends TestCase
{
    private string $coreAppRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreAppRoot = dirname(__DIR__, 5).'/core/app';
    }

    public function test_projects_module_uses_project_factory_and_no_implementation_instanceof(): void
    {
        $source = file_get_contents($this->coreAppRoot.'/System/Projects.php');
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
        $source = file_get_contents($this->coreAppRoot.'/System/Projects.php');
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
        $source = file_get_contents($this->coreAppRoot.'/System/Projects.php');
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
            $source = file_get_contents($this->coreAppRoot.'/'.$path);
            $this->assertStringNotContainsString('App\\Lib\\Copy\\AccountClone', $source, $path);
            $this->assertStringNotContainsString('App\\Lib\\Copy\\Push', $source, $path);
            $this->assertStringNotContainsString('new Push()', $source, $path);
            $this->assertStringContainsString('projects()->', $source, $path);
        }

        $this->assertStringContainsString('projects()->clone(', file_get_contents($this->coreAppRoot.'/Http/Controllers/UserController.php'));
        $this->assertStringContainsString('projects()->copy(', file_get_contents($this->coreAppRoot.'/Jobs/CreateStaging.php'));
        $this->assertStringContainsString('projects()->pushToApp(', file_get_contents($this->coreAppRoot.'/Jobs/PushState.php'));

        $stagingHttp = file_get_contents($this->coreAppRoot.'/Http/Controllers/User/StagingController.php');
        $this->assertStringNotContainsString('App\\Lib\\Copy', $stagingHttp);
        $this->assertStringContainsString('CreateStaging::dispatch', $stagingHttp);
        $this->assertStringContainsString('PushState::dispatch', $stagingHttp);
    }

    public function test_clone_or_copy_applies_deploy_snapshot_before_volumes_and_start(): void
    {
        $source = file_get_contents($this->coreAppRoot.'/System/Projects.php');
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

    public function test_a_container_copy_gets_its_own_app_database_and_run_file_values_before_its_volumes(): void
    {
        $source = (string) file_get_contents($this->coreAppRoot.'/System/Projects.php');
        $paused = $this->methodBody($source, 'copyWhileSourcePaused');
        $copyBlock = $this->methodBody($source, 'copyHomeAndApp');

        $homePos = strpos($copyBlock, 'copyHomeDirFrom');
        $databasePos = strpos($copyBlock, 'appDatabaseCopy()->intoCopy($copy->source, $copy->dest, $copy->copyDatabases)');
        $repointPos = strpos($copyBlock, 'repointCopiedRunFile($copy->source)');

        $this->assertNotFalse($databasePos, 'a container copy must get its own app database');
        $this->assertNotFalse($repointPos, 'a container copy must point its run file at itself');
        $this->assertLessThan($databasePos, $homePos);
        $this->assertLessThan($repointPos, $databasePos, 'the run file is pointed at a database that exists');
        $this->assertLessThan(
            strpos($paused, 'copyVolumeData($copy)'),
            strpos($paused, 'copyHomeAndApp($copy)'),
            'compose create must see the copy\'s own values',
        );
        $this->assertStringContainsString('prepareVolumes($copy->destProject)', $this->methodBody($source, 'copyVolumeData'));
    }

    public function test_a_container_copy_stores_its_env_vars_once_its_run_file_is_its_own(): void
    {
        $source = (string) file_get_contents($this->coreAppRoot.'/System/Projects.php');
        $copyBlock = $this->methodBody($source, 'copyHomeAndApp');
        $pushBlock = $this->methodBody($source, 'pushToApp');

        $repointPos = strpos($copyBlock, 'repointCopiedRunFile($copy->source)');
        $adoptPos = strpos($copyBlock, 'adoptCopiedEnvVars($copy->source)');
        $this->assertNotFalse($adoptPos, 'a container copy must store the env vars its files were written with');
        $this->assertLessThan($adoptPos, $repointPos, 'the env vars are pointed at a database that exists');

        $committedPos = strpos($pushBlock, 'throw $this->rollBackPush(');
        $commitPos = strpos($pushBlock, 'return $this->commitPush($push);');
        $this->assertNotFalse($commitPos);
        $this->assertLessThan($commitPos, $committedPos, 'only once the pushed files are kept');
        $this->assertStringContainsString(
            "if (\$push->files && \$push->isDindDest) {\n            \$push->toProject->adoptCopiedEnvVars(\$push->from);",
            $this->methodBody($source, 'commitPush'),
            'a push of the files must store the pushed env vars on the target',
        );
    }

    public function test_the_copied_run_files_images_are_got_from_a_first_copy_before_the_source_stops(): void
    {
        $source = (string) file_get_contents($this->coreAppRoot.'/System/Projects.php');
        $copyBlock = $this->methodBody($source, 'cloneOrCopy');
        $pushBlock = $this->methodBody($source, 'pushToApp');

        $this->assertStringContainsString(
            'provideCopyImagesWhileRunning($copy->sourceProject, $copy->destProject)',
            $this->methodBody($source, 'prepareCopyWhileRunning'),
            'a container copy must get its images',
        );
        $this->assertStringContainsString('pauseAppForCopy($copy->sourceProject)', $this->methodBody($source, 'copyWhileSourcePaused'));
        $this->assertLessThan(
            strpos($copyBlock, 'copyWhileSourcePaused($copy)'),
            strpos($copyBlock, 'prepareCopyWhileRunning($copy)'),
        );
        $this->assertStringNotContainsString('provideCopiedImages(', $copyBlock);
        $clone = $this->methodBody($source, 'provideCopyImagesWhileRunning');
        $this->assertLessThan(
            strpos($clone, 'provideCopiedImages()'),
            strpos($clone, 'copyHomeDirFrom($sourceProject, sourceRunning: true)'),
        );

        $this->assertStringContainsString(
            'provideIncomingImagesWhileRunning($push->fromHome, $push->incomingDir, $push->toProject)',
            $this->methodBody($source, 'prepareWhileRunning'),
            'a container push must get its images',
        );
        $this->assertLessThan(
            strpos($pushBlock, 'pauseAppForCopy($push->fromProject)'),
            strpos($pushBlock, 'prepareWhileRunning($push)'),
        );
        $this->assertStringNotContainsString('provideCopiedImages(', $pushBlock);
        $push = $this->methodBody($source, 'provideIncomingImagesWhileRunning');
        $this->assertLessThan(
            strpos($push, 'provideCopiedImages(self::incomingRunFile($incomingDir))'),
            strpos($push, 'handStagedHomeToAccount($incomingDir)'),
        );
    }

    public function test_clone_or_copy_reads_container_wordpress_hosts_while_the_source_runs_and_rewrites_once_the_copy_runs(): void
    {
        $source = file_get_contents($this->coreAppRoot.'/System/Projects.php');
        $this->assertIsString($source);
        $copyBlock = $this->methodBody($source, 'cloneOrCopy');
        $startBlock = $this->methodBody($source, 'startCopiedApp');

        $this->assertStringContainsString(
            'containerSiteCopy()->sourceHosts($copy->source)',
            $this->methodBody($source, 'prepareCopyWhileRunning'),
        );
        $this->assertLessThan(
            strpos($copyBlock, 'copyWhileSourcePaused($copy)'),
            strpos($copyBlock, 'prepareCopyWhileRunning($copy)'),
            'the source answers WP-CLI only before it is paused',
        );
        $startPos = strpos($startBlock, 'startUserApp()');
        $rewritePos = strpos($startBlock, 'pointContainerWordPressAtItself($copy->dest');
        $this->assertNotFalse($rewritePos);
        $this->assertLessThan($rewritePos, $startPos, 'WP-CLI runs in the copy\'s running containers');
    }

    public function test_every_copy_resumes_its_source_as_it_was_stopped(): void
    {
        $projects = file_get_contents($this->coreAppRoot . '/System/Projects.php');
        $this->assertStringNotContainsString('resumeAppAfterCopy($copy->sourceProject)', $projects);
        $this->assertStringNotContainsString('resumeAppAfterCopy($fromProject)', $projects);
        $this->assertStringContainsString('resumeSourceAfterCopy($copy->sourceProject)', $projects);
        $this->assertStringContainsString('resumeSourceAfterCopy($fromProject)', $projects);
        $this->assertStringContainsString(
            '->resumeSourceAfterCopy()',
            file_get_contents($this->coreAppRoot . '/Jobs/CreateStaging.php'),
        );
        $this->assertStringContainsString(
            '$from)?->resumeSourceAfterCopy()',
            file_get_contents($this->coreAppRoot . '/Jobs/PushState.php'),
        );
    }

    public function test_production_validation_uses_system_projects_not_lib_copy(): void
    {
        $paths = [
            'Http/Requests/ProjectStagingRequest.php',
            'Http/Requests/ProjectPushRequest.php',
            'Http/Controllers/User/StagingController.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($this->coreAppRoot.'/'.$path);
            $this->assertStringNotContainsString('App\\Lib\\Copy', $source, $path);
            $this->assertStringNotContainsString('ProjectCopyGuards', $source, $path);
            $this->assertStringContainsString('Projects', $source, $path);
        }
    }

    private function methodBody(string $source, string $method): string
    {
        $begin = strpos($source, "function {$method}(");
        $this->assertNotFalse($begin, $method);
        preg_match('/\n    (?:public|protected|private)(?: static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $begin + 10);

        return substr($source, $begin, ($m[0][1] ?? strlen($source)) - $begin);
    }
}
