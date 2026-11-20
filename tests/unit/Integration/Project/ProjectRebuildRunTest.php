<?php

namespace Tests\Integration\Project;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Findings\Finding;
use App\Lib\Project\ProjectRebuild;
use App\Lib\Upload\ArchiveSource;
use App\Lib\Upload\ArchiveSources;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A rebuild or an archive deploy run in this process: what it asks of the project, what it records on the account,
 * and how a failure part way reaches the caller and the log.
 */
class ProjectRebuildRunTest extends TestCase
{
    use InMemoryDatabase;

    private Project&MockInterface $project;

    private Webserver&MockInterface $webserver;

    private ArchiveSources&MockInterface $archives;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->webserver = Mockery::mock(Webserver::class);
        $system = Mockery::mock(System::class);
        $system->allows('webserver')->andReturn($this->webserver);
        $this->project = Mockery::mock(Project::class);
        $this->project->allows('system')->andReturn($system);
        $this->archives = Mockery::mock(ArchiveSources::class);
    }

    public function test_a_rebuild_rebuilds_the_source_re_renders_the_proxy_and_records_success(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind', 'deployment_status' => 'failed', 'error' => 'boom']);
        $logger = Mockery::mock(DeployLogger::class);
        $this->project->expects('rebuildFromSource')->with($logger, '/home/shop/site.zip');
        $this->webserver->expects('rebuildDomains');

        $this->assertSame([], $this->rebuild()->run($user, ProjectRebuild::REBUILD, $logger, '/home/shop/site.zip'));

        $details = User::findByUsernameOrFail('shop')->getDetails();
        $this->assertSame('success', $details['deployment_status']);
        $this->assertNull($details['error']);
    }

    public function test_attaching_a_source_rebuilds_from_the_project_without_an_archive(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind', 'deployment_status' => 'success', 'deployment_warnings' => []]);
        $this->project->expects('rebuildFromSource')->with(null, null);
        $this->webserver->expects('rebuildDomains');

        $this->assertSame([], $this->rebuild()->run($user, ProjectRebuild::ATTACH_SOURCE, null, '/ignored.zip'));

        $this->assertFalse($user->isDirty(), 'a clean success is not written again');
    }

    public function test_a_refusal_from_the_rebuild_reaches_the_caller_as_it_is(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $refusal = ValidationException::withMessages(['zip_path' => 'not an archive']);
        $this->project->expects('rebuildFromSource')->andThrow($refusal);

        try {
            $this->rebuild()->run($user, ProjectRebuild::REBUILD, null, null);
            $this->fail('the rebuild went through');
        } catch (ValidationException $e) {
            $this->assertSame($refusal, $e);
        }
    }

    public function test_a_rebuild_that_breaks_is_answered_as_a_failed_deploy_and_its_log_finished(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->project->expects('rebuildFromSource')->andThrow(new RuntimeException('compose up exited 1'));
        $this->webserver->shouldNotReceive('rebuildDomains');
        $finished = null;
        $logger = Mockery::mock(DeployLogger::class);
        $logger->allows('currentStage')->andReturn(DeployLogger::STAGE_RUNNING);
        $logger->expects('recordFailureOutput')->with('compose up exited 1');
        $logger->allows('readLatest')->andReturn(['status' => DeployLogger::STATUS_RUNNING]);
        $logger->expects('finishFailed')->andReturnUsing(static function (Finding $failure) use (&$finished): void {
            $finished = $failure;
        });

        $problem = $this->problemOf(fn () => $this->rebuild()->run($user, ProjectRebuild::REBUILD, $logger, null));

        $this->assertSame(DeployLogger::STAGE_RUNNING, $problem['stage']);
        $this->assertSame($finished->code, $problem['code']);
        $this->assertStringContainsString('compose up exited 1', $problem['message']);
        $this->assertNotSame('success', User::findByUsernameOrFail('shop')->getDetails()['deployment_status'] ?? null);
    }

    public function test_an_archive_deploy_cancelled_is_answered_with_its_stage(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $archive = ArchiveSource::upload('upl_1');
        $this->archives->expects('materialise')->with($user, $archive)->andThrow(new DeployCancelledException('Cancelled by the user'));
        $this->archives->shouldNotReceive('release');
        $logger = Mockery::mock(DeployLogger::class);
        $logger->allows('currentStage')->andReturn(DeployLogger::STAGE_CLONING);
        $logger->expects('finish')->with(DeployLogger::STATUS_CANCELLED, 'Cancelled by the user');

        $problem = $this->problemOf(fn () => $this->rebuild()->run($user, ProjectRebuild::DEPLOY_ARCHIVE, $logger, null, [], $archive));

        $this->assertSame(['deploy_cancelled', 'Cancelled by the user', DeployLogger::STAGE_CLONING], [$problem['code'], $problem['message'], $problem['stage']]);
    }

    public function test_an_archive_deploy_that_fails_finishes_its_log_failed(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $this->archives->expects('materialise')->andThrow(new RuntimeException('the download stalled'));
        $finished = null;
        $logger = Mockery::mock(DeployLogger::class);
        $logger->allows('currentStage')->andReturn(DeployLogger::STAGE_PREPARING);
        $logger->expects('recordFailureOutput')->with('the download stalled');
        $logger->expects('finishFailed')->andReturnUsing(static function (Finding $failure) use (&$finished): void {
            $finished = $failure;
        });

        $problem = $this->problemOf(fn () => $this->rebuild()->run($user, ProjectRebuild::DEPLOY_ARCHIVE, $logger, '/home/shop/site.zip'));

        $this->assertSame(DeployLogger::STAGE_PREPARING, $problem['stage']);
        $this->assertSame($finished->code, $problem['code']);
        $this->assertStringContainsString('the download stalled', $problem['message']);
    }

    public function test_an_archive_is_looked_for_in_the_projects_own_home(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $files = Mockery::mock(FileManager::class);
        $files->allows('homeDirPath')->andReturn('/home/shop/');
        $files->expects('resolvedFile')->with('/home/shop/site.zip')->andReturnNull();
        $this->project->allows('fileManager')->andReturn($files);

        try {
            $this->rebuild()->assertArchive($user, 'site.zip');
            $this->fail('a missing archive was accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['zip_path' => ['Archive not found: /home/shop/site.zip']], $e->errors());
        }
    }

    public function test_a_queue_lock_that_cannot_be_opened_refuses_to_start(): void
    {
        $user = $this->makeUser('pr'.bin2hex(random_bytes(4)), ['template' => 'dind']);
        $paths = new DeployLogPaths($user->username);
        File::ensureDirectoryExists($paths->queueLock());

        try {
            (new ProjectRebuild)->whileIdle($user, fn () => $this->fail('started without the lock'));
            $this->fail('started without the lock');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame('Could not lock the project to start its deploy; try again.', $e->getMessage());
        } finally {
            File::deleteDirectory($paths->directory());
        }
    }

    private function rebuild(): ProjectRebuild
    {
        return new class ($this->project, $this->archives) extends ProjectRebuild {
            public function __construct(private readonly Project $fake, private readonly ArchiveSources $fakeArchives) {}

            protected function project(User $user): Project
            {
                return $this->fake;
            }

            protected function archives(): ArchiveSources
            {
                return $this->fakeArchives;
            }
        };
    }

    /** @return array<string, mixed> */
    private function problemOf(\Closure $call): array
    {
        try {
            $call();
        } catch (ProblemException $e) {
            return $e->problems[0];
        }
        $this->fail('expected a ProblemException');
    }
}
