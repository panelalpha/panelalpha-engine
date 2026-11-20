<?php

namespace Tests\Integration\System\Project\Deployment;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as SystemProject;
use App\System\Project\Deployment\DindDeployMechanics;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Domain as DomainProject;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** What the DinD deploy mechanics do with the account, its checkout and its app, against a faked host. */
class DindDeployMechanicsBehaviourTest extends TestCase
{
    private string $username = '';

    private string $home = '';

    private ?DeployLogger $deploy = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.DIND_RUNTIME' => 'runc']);
        $this->username = 'ddm-'.bin2hex(random_bytes(4));
        $this->home = '/home/'.$this->username;
    }

    protected function tearDown(): void
    {
        $this->deploy = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_the_main_domain_is_returned_and_its_absence_refused(): void
    {
        $domain = new DomainModel;
        $user = $this->user();
        $user->mainDomain = $domain;

        $this->assertSame($domain, (new DindDeployMechanics($this->dind($user)))->requireMainDomain());

        $user->mainDomain = null;
        $this->expectExceptionMessage("Project '{$this->username}' has no main domain.");
        (new DindDeployMechanics($this->dind($user)))->requireMainDomain();
    }

    public function test_a_git_project_and_a_dind_project_without_git_are_told_apart(): void
    {
        $git = new DindDeployMechanics($this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git'])));
        $plain = new DindDeployMechanics($this->dind($this->user(['template' => 'dind'])));
        $other = new DindDeployMechanics($this->dind($this->user(['template' => 'php'])));

        $this->assertTrue($git->hasGitProject());
        $this->assertFalse($git->isDindWithoutGit());
        $this->assertFalse($plain->hasGitProject());
        $this->assertTrue($plain->isDindWithoutGit());
        $this->assertFalse($other->isDindWithoutGit());
    }

    public function test_a_name_already_held_on_the_host_is_refused_before_anything_is_made(): void
    {
        $this->app->instance(System::class, new RecordingSystem);
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('hostingExists')->andReturn(true);
        $project->shouldReceive('model')->andReturn($this->user());
        $project->shouldNotReceive('createDirectories', 'createFromTemplate', 'up', 'delete');
        $mechanics = new DindDeployMechanics($this->dind($this->user(), $project));

        try {
            $mechanics->prepareHostingEnvironment();
            $this->fail('a held name must be refused');
        } catch (\Exception $e) {
            $this->assertStringContainsString($this->username, $e->getMessage());
        }
        $this->assertFalse($mechanics->removedTheAccount());
    }

    public function test_a_failed_account_start_removes_the_account_even_when_its_removal_fails(): void
    {
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('hostingExists')->andReturn(false);
        $project->shouldReceive('createDirectories', 'createFromTemplate')->once();
        $project->shouldReceive('syncLinuxUser')->once()->andReturn(['UID' => 1000, 'GID' => 1000]);
        $project->shouldReceive('model')->andReturn($this->user());
        $project->shouldReceive('up')->once()->andThrow(new \Exception('the account image did not build'));
        $project->shouldReceive('delete')->once()->andThrow(new \Exception('nothing to delete'));
        $mechanics = new DindDeployMechanics($this->dind($this->user(), $project));

        try {
            $mechanics->prepareHostingEnvironment();
            $this->fail('the failed start must surface');
        } catch (\Exception $e) {
            $this->assertSame('the account image did not build', $e->getMessage());
        }
        $this->assertTrue($mechanics->removedTheAccount());
    }

    public function test_a_project_without_git_is_prepared_from_the_files_it_has(): void
    {
        $dind = $this->dind($this->user(['template' => 'dind']));
        $dind->shouldNotReceive('preCheckFromSources');
        $dind->shouldReceive('prepareFromSources')->once();

        (new DindDeployMechanics($dind))->ingestApplicationSource();
    }

    public function test_a_git_project_whose_clone_fails_is_not_prepared(): void
    {
        $host = $this->cloneFails();
        $dind = $this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git']), null, $host);
        $dind->shouldReceive('preCheckFromSources')->once();
        $dind->shouldNotReceive('prepareFromSources');

        try {
            (new DindDeployMechanics($dind))->ingestApplicationSource();
            $this->fail('the failed clone must surface');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Repository not found', $e->getMessage());
        }
    }

    public function test_a_failed_reclone_leaves_the_running_app_alone(): void
    {
        $host = $this->cloneFails();
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldNotReceive('projectAction');
        $dind = $this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git']), null, $host);
        $dind->shouldReceive('app')->andReturn($app);
        $dind->shouldReceive('preCheckFromSources')->once();
        $dind->shouldNotReceive('prepareFromSources');

        try {
            (new DindDeployMechanics($dind))->ingestForWipeRebuild(null);
            $this->fail('the failed clone must surface');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Repository not found', $e->getMessage());
        }
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::CHECKOUT));
    }

    public function test_the_application_runs_only_for_a_dind_project_whose_account_is_up(): void
    {
        $running = Mockery::mock(SystemProject::class);
        $running->shouldReceive('isRunning')->andReturn(true);
        $stopped = Mockery::mock(SystemProject::class);
        $stopped->shouldReceive('isRunning')->andReturn(false);
        $notAsked = Mockery::mock(SystemProject::class);
        $notAsked->shouldNotReceive('isRunning');

        $this->assertTrue((new DindDeployMechanics($this->dind($this->user(['template' => 'dind']), $running)))->isApplicationEnvironmentRunning());
        $this->assertFalse((new DindDeployMechanics($this->dind($this->user(['template' => 'dind']), $stopped)))->isApplicationEnvironmentRunning());
        $this->assertFalse((new DindDeployMechanics($this->dind($this->user(['template' => 'php']), $notAsked)))->isApplicationEnvironmentRunning());
    }

    public function test_a_checkout_rebuild_reusing_the_running_account_only_syncs_the_linux_user(): void
    {
        [$project, $calls] = $this->recordingProject();

        (new DindDeployMechanics($this->dind($this->user(), $project)))->syncHostingForCheckoutRebuild(true);

        $this->assertSame(['syncLinuxUser'], $calls->list);
    }

    public function test_a_checkout_rebuild_without_a_running_account_rebuilds_and_restarts_it(): void
    {
        [$project, $calls] = $this->recordingProject();

        (new DindDeployMechanics($this->dind($this->user(), $project)))->syncHostingForCheckoutRebuild(false);

        $this->assertSame([
            'createHomeDir', 'createProjectDir', 'syncLinuxUser', 'configureQuota',
            'createFromTemplate', 'buildIfMissing', 'down', 'up',
        ], $calls->list);
    }

    public function test_a_git_source_rebuild_leaves_the_app_running_for_the_clone_to_stop(): void
    {
        $this->startLog();
        [$project, $calls] = $this->recordingProject();
        $dind = $this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git']), $project);
        $dind->shouldNotReceive('app');

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        $this->assertSame(['prepareLinuxIsolation', 'recreateOuterCompose'], $calls->list);
        $this->assertSame([
            ['info', 'Preparing the account directories and Linux user'],
            ['info', "Recreating the account's outer compose file"],
        ], $this->lines());
    }

    public function test_an_archive_rebuild_without_an_app_imports_the_archive(): void
    {
        [$project, $calls] = $this->recordingProject();
        $dind = $this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git']), $project);
        $dind->shouldReceive('app')->andReturn(null);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild('/tmp/upload.zip');

        $this->assertSame(['prepareLinuxIsolation', 'importProjectArchive:/tmp/upload.zip', 'recreateOuterCompose'], $calls->list);
    }

    public function test_an_app_whose_mounts_cannot_be_read_is_stopped_and_a_failed_stop_is_warned(): void
    {
        $this->startLog();
        Log::spy();
        [$project, $calls] = $this->recordingProject(running: true);
        $host = $this->host(['account-docker.listContainers' => FakeProcess::failed('daemon down')]);
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldReceive('projectAction')->with('down')->once()->andReturn(['exit_code' => 1, 'stdout' => '', 'stderr' => " busy volume \n"]);
        $dind = $this->dind($this->user(['template' => 'dind']), $project, $host);
        $dind->shouldReceive('app')->andReturn($app);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        $this->assertContains(['info', 'Stopped the app first: its mounts could not be read'], $this->lines());
        Log::shouldHaveReceived('warning')->once()->with("Could not stop the app before the wipe rebuild of {$this->username}: busy volume");
        $this->assertSame(['isRunning', 'prepareLinuxIsolation', 'recreateOuterCompose'], $calls->list);
    }

    public function test_a_stopped_app_that_fails_to_stop_again_is_not_warned_about(): void
    {
        Log::spy();
        [$project] = $this->recordingProject(running: false);
        $host = $this->host(['account-docker.listContainers' => FakeProcess::failed('daemon down')]);
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldReceive('projectAction')->with('down')->once()->andReturn(['exit_code' => 1, 'stdout' => 'gone', 'stderr' => '']);
        $dind = $this->dind($this->user(['template' => 'dind']), $project, $host);
        $dind->shouldReceive('app')->andReturn($app);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_an_app_that_mounts_nothing_and_runs_nothing_keeps_serving(): void
    {
        $this->startLog();
        [$project] = $this->recordingProject();
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldNotReceive('projectAction');
        $dind = $this->dind($this->user(['template' => 'dind']), $project, $this->host(['account-docker.listContainers' => FakeProcess::ok('[]')]));
        $dind->shouldReceive('app')->andReturn($app);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        $this->assertContains(['info', 'Kept the running app up during the build'], $this->lines());
    }

    public function test_a_running_app_reading_its_checkout_through_a_bind_keeps_serving_from_the_tree_moved_aside(): void
    {
        $this->startLog();
        [$project] = $this->recordingProject();
        $host = $this->runningApp(binds: true);
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldNotReceive('projectAction');
        $dind = $this->dind($this->user(['template' => 'dind']), $project, $host);
        $dind->shouldReceive('app')->andReturn($app);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        $this->assertContains(['info', 'Kept the running app up during the build: web mounts ./data, so the checkout it reads moved to ~/'
            .CheckoutAside::DIR.' until the new version replaces it'], $this->lines());
        $this->assertSame(['files.removeTree', 'files.rename', 'files.copyTree'], $this->moves($host));
        $this->assertSame(['app-1'], (new GenerationState($this->username))->get(GenerationState::CHECKOUT)['containers'] ?? null);
    }

    public function test_a_running_app_that_mounts_nothing_has_its_checkout_moved_aside_too(): void
    {
        $this->startLog();
        [$project] = $this->recordingProject();
        $host = $this->runningApp(binds: false);
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldNotReceive('projectAction');
        $dind = $this->dind($this->user(['template' => 'dind']), $project, $host);
        $dind->shouldReceive('app')->andReturn($app);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        $this->assertContains(['info', 'Kept the running app up during the build; the checkout it was deployed from moved to ~/'
            .CheckoutAside::DIR.', and comes back if the new version fails'], $this->lines());
        $this->assertFalse((new GenerationState($this->username))->get(GenerationState::CHECKOUT)['binds'] ?? true);
    }

    public function test_a_bound_app_whose_checkout_cannot_be_moved_aside_is_stopped(): void
    {
        $this->startLog();
        [$project] = $this->recordingProject(running: true);
        $host = $this->runningApp(binds: true, moves: false);
        $app = Mockery::mock(AbstractApplication::class);
        $app->shouldReceive('projectAction')->with('down')->once()->andReturn(['exit_code' => 0, 'stdout' => '', 'stderr' => '']);
        $dind = $this->dind($this->user(['template' => 'dind']), $project, $host);
        $dind->shouldReceive('app')->andReturn($app);

        (new DindDeployMechanics($dind))->syncHostingForSourceRebuild(null);

        $this->assertContains(['info', 'Stopped the app first: web mounts ./data'], $this->lines());
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::CHECKOUT));
    }

    public function test_a_wipe_rebuild_from_an_archive_prepares_the_app_without_recloning(): void
    {
        $dind = $this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git']));
        $dind->shouldNotReceive('preCheckFromSources');
        $dind->shouldReceive('prepareFromSources')->once();

        (new DindDeployMechanics($dind))->ingestForWipeRebuild('/tmp/upload.zip');
    }

    public function test_an_archive_with_no_app_running_is_imported_then_prepared(): void
    {
        [$project, $calls] = $this->recordingProject();
        $dind = $this->dind($this->user(['template' => 'dind']), $project);
        $dind->shouldReceive('app')->andReturn(null);
        $dind->shouldReceive('prepareFromSources')->once()->andReturnUsing(static function () use ($calls): void {
            $calls->list[] = 'prepareFromSources';
        });

        (new DindDeployMechanics($dind))->ingestArchive('/tmp/upload.zip');

        $this->assertSame(['importProjectArchive:/tmp/upload.zip', 'prepareFromSources'], $calls->list);
    }

    public function test_a_checkout_this_process_moved_aside_is_said_and_not_moved_again(): void
    {
        $this->startLog();
        (new GenerationState($this->username))->put(GenerationState::CHECKOUT, ['owner' => GenerationState::owner()]);
        $host = $this->host([]);
        $dind = $this->dind($this->user(['template' => 'dind', 'git_repo' => 'https://example.test/a.git']), null, $host);
        $dind->shouldReceive('app')->andReturn(Mockery::mock(AbstractApplication::class));
        $dind->shouldReceive('prepareFromSources')->once();

        (new DindDeployMechanics($dind))->reprepareApplicationFromCheckout();

        $this->assertContains(['info', 'Kept the running app up during the build; the checkout it was deployed from moved to ~/'
            .CheckoutAside::DIR.' before the change, and comes back if the new version fails'], $this->lines());
        $this->assertSame([], $this->moves($host));
    }

    public function test_a_pull_under_a_running_app_builds_on_a_copy_of_its_checkout(): void
    {
        $this->startLog();
        $host = $this->runningApp(binds: true);
        $dind = $this->dind($this->user(['template' => 'dind']), null, $host);
        $dind->shouldReceive('app')->andReturn(Mockery::mock(AbstractApplication::class));
        $dind->shouldReceive('prepareFromSources')->once();

        (new DindDeployMechanics($dind))->reprepareApplicationFromCheckout();

        $this->assertSame(['files.removeTree', 'files.rename', 'files.copyTree'], $this->moves($host));
        $this->assertFalse((new GenerationState($this->username))->get(GenerationState::CHECKOUT)['copy_back_pending'] ?? true);
    }

    public function test_a_pull_with_no_container_running_moves_nothing(): void
    {
        $host = $this->host(['account-docker.listContainers' => FakeProcess::ok('[]')]);
        $dind = $this->dind($this->user(['template' => 'dind']), null, $host);
        $dind->shouldReceive('app')->andReturn(Mockery::mock(AbstractApplication::class));
        $dind->shouldReceive('prepareFromSources')->once();

        (new DindDeployMechanics($dind))->reprepareApplicationFromCheckout();

        $this->assertSame([], $this->moves($host));
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::CHECKOUT));
    }

    public function test_a_succeeded_redeploy_forgets_a_checkout_record_with_nothing_aside(): void
    {
        $state = new GenerationState($this->username);
        $state->put(GenerationState::CHECKOUT, ['path' => $this->home.'/'.CheckoutAside::DIR, 'containers' => ['app-1'], 'owner' => GenerationState::owner()]);
        $dind = $this->dind($this->user(['template' => 'dind']), null, $this->host(['files.isDirectory' => FakeProcess::failed()]));

        (new DindDeployMechanics($dind))->settleRedeploy(true);

        $this->assertNull($state->get(GenerationState::CHECKOUT));
    }

    public function test_the_running_version_is_recorded_as_unread_when_its_containers_cannot_be_listed(): void
    {
        $host = $this->host(['account-docker.listContainers' => FakeProcess::failed('daemon down')]);
        $dind = $this->dind($this->user(['template' => 'dind']), null, $host);

        (new DindDeployMechanics($dind))->recordRunningVersion();

        $state = new GenerationState($this->username);
        $this->assertNotNull($state->get(GenerationState::UNREAD));
        $this->assertNull($state->get(GenerationState::ROUTES));
        $this->assertNull($state->get(GenerationState::IMAGES));
    }

    public function test_publishing_starting_and_aborting_are_handed_to_the_project(): void
    {
        $domain = new DomainModel;
        $published = Mockery::mock(DomainProject::class);
        $published->shouldReceive('create')->once();
        $project = Mockery::mock(SystemProject::class);
        $project->shouldReceive('domain')->with($domain)->once()->andReturn($published);
        $project->shouldReceive('startUserApp')->once()->andReturn(['exit_code' => 0, 'stdout' => 'started', 'stderr' => '']);
        $project->shouldReceive('abortRunningDeploy')->with(removeVolumes: true)->once();
        $mechanics = new DindDeployMechanics($this->dind($this->user(), $project));

        $mechanics->publishDomain($domain);
        $this->assertSame(['exit_code' => 0, 'stdout' => 'started', 'stderr' => ''], $mechanics->startApplication());
        $mechanics->abortPartialDeploy(true);
    }

    public function test_serving_warnings_come_from_the_recorded_health_check(): void
    {
        $healthy = new DindDeployMechanics($this->dind($this->user()));
        $silent = new DindDeployMechanics($this->dind($this->user([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 8000, 'status' => 'refused', 'http_code' => null]],
        ])));

        $this->assertSame([], $healthy->servingWarnings());
        $this->assertSame([AppHealth::CODE_NOT_ANSWERING], array_map(static fn ($finding) => $finding->code, $silent->servingWarnings()));
    }

    public function test_a_public_url_that_does_not_resolve_is_warned_by_its_domain_name(): void
    {
        $domain = new DomainModel;
        $domain->domain = 'shop.example.test';
        $resolving = new DindDeployMechanics($this->dind($this->user()));
        $hidden = new DindDeployMechanics($this->dind($this->user(['domain' => ['publicly_resolvable' => false]])));

        $this->assertSame([], $resolving->publicUrlWarnings($domain));
        $warnings = $hidden->publicUrlWarnings($domain);
        $this->assertSame([PublicUrl::UNRESOLVABLE], array_map(static fn ($finding) => $finding->code, $warnings));
        $this->assertSame('shop.example.test', $warnings[0]->facts['domain']);
    }

    public function test_the_custom_environment_hint_is_given_only_when_they_were_used(): void
    {
        $this->assertNull((new DindDeployMechanics($this->dind($this->user())))->customEnvFailureHint());
        $this->assertSame(
            'Deploy failed with custom environment variables — consider retrying without them to see whether they caused it.',
            (new DindDeployMechanics($this->dind($this->user(['used_custom_env_vars' => true]))))->customEnvFailureHint(),
        );
    }

    public function test_a_partial_success_records_its_warnings_and_clears_the_error(): void
    {
        $user = $this->user(['error' => 'old failure', 'app_stopped_by_request' => true]);

        (new DindDeployMechanics($this->dind($user)))->persistPartialSuccess([new Finding('app_not_answering', 'did not answer')]);

        $details = $user->getDetails();
        $this->assertSame('partial', $details['deployment_status']);
        $this->assertNull($details['error']);
        $this->assertNull($details['app_stopped_by_request']);
        $this->assertSame('app_not_answering', $details['deployment_warnings'][0]['code'] ?? null);
        $this->assertSame(1, $user->saves);
    }

    public function test_a_success_is_recorded_and_saved(): void
    {
        $user = $this->user(['error' => 'old failure', 'deployment_status' => 'failed']);

        (new DindDeployMechanics($this->dind($user)))->persistSuccess();

        $this->assertSame('success', $user->getDetails()['deployment_status']);
        $this->assertNull($user->getDetails()['error']);
        $this->assertSame(1, $user->saves);
    }

    /** A host whose every git clone fails as an unknown repository does. */
    private function cloneFails(): RecordingSystem
    {
        $host = $this->host([]);
        $host->answers['clone'] = FakeProcess::failed("remote: Repository not found.\nfatal: repository 'https://example.test/a.git/' not found", 128);

        return $host;
    }

    /** A host whose stack lists one running container, `web`, with or without a bind under ~/project. */
    private function runningApp(bool $binds, bool $moves = true): RecordingSystem
    {
        $mounts = $binds ? [['Type' => 'bind', 'Source' => $this->home.'/project/data']] : [];
        $answers = [
            'account-docker.listContainers' => FakeProcess::ok(json_encode([['Id' => 'app-1', 'Names' => ['/web-1']]])),
            'account-docker.containers' => FakeProcess::ok(json_encode([[
                'Id' => 'app-1',
                'Config' => ['Labels' => ['com.docker.compose.service' => 'web']],
                'Mounts' => $mounts,
            ]])),
            'files.isDirectory' => FakeProcess::failed(),
        ];
        if (! $moves) {
            $answers['files.rename'] = FakeProcess::failed('cannot move');
        }

        return $this->host($answers);
    }

    /**
     * @param  array<string, Process>  $answers  by broker action; any other command succeeds with no output
     */
    private function host(array $answers): RecordingSystem
    {
        $host = new class extends RecordingSystem
        {
            /** @var array<string, Process> */
            public array $answers = [];

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                $action = is_array($cmd) ? LocalRoot::action($cmd) : null;
                if (isset($this->answers['clone']) && str_contains(is_array($cmd) ? implode(' ', $cmd) : $cmd, "'clone'")) {
                    return $this->answers['clone'];
                }

                return $this->answers[$action ?? ''] ?? FakeProcess::ok();
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }
        };
        $host->answers = $answers;

        return $host;
    }

    /** @return list<string> */
    private function moves(RecordingSystem $host): array
    {
        $actions = array_map(static fn ($cmd): ?string => is_array($cmd) ? LocalRoot::action($cmd) : null, $host->commands);

        return array_values(array_filter($actions, static fn (?string $action): bool => in_array($action, ['files.removeTree', 'files.rename', 'files.copyTree'], true)));
    }

    /** @return array{SystemProject&MockInterface, object{list: list<string>}} */
    private function recordingProject(bool $running = false): array
    {
        $calls = new class
        {
            /** @var list<string> */
            public array $list = [];
        };
        $project = Mockery::mock(SystemProject::class);
        foreach (['createHomeDir', 'createProjectDir', 'createFromTemplate', 'buildIfMissing', 'down', 'up', 'prepareLinuxIsolation', 'recreateOuterCompose'] as $method) {
            $project->shouldReceive($method)->andReturnUsing(static function () use ($calls, $method): void {
                $calls->list[] = $method;
            });
        }
        $project->shouldReceive('syncLinuxUser')->andReturnUsing(static function () use ($calls): array {
            $calls->list[] = 'syncLinuxUser';

            return ['UID' => 1000, 'GID' => 1000];
        });
        $project->shouldReceive('configureQuota')->andReturnUsing(static function () use ($calls): bool {
            $calls->list[] = 'configureQuota';

            return true;
        });
        $project->shouldReceive('isRunning')->andReturnUsing(static function () use ($calls, $running): bool {
            $calls->list[] = 'isRunning';

            return $running;
        });
        $project->shouldReceive('importProjectArchive')->andReturnUsing(static function (string $zip) use ($calls): void {
            $calls->list[] = 'importProjectArchive:'.$zip;
        });

        return [$project, $calls];
    }

    /** @param  array<string, mixed>  $details */
    private function user(array $details = []): ModelsUser
    {
        $user = new class extends ModelsUser
        {
            public int $saves = 0;

            public ?DomainModel $mainDomain = null;

            public function save(array $options = []): bool
            {
                $this->saves++;

                return true;
            }

            public function getMainDomain(): ?DomainModel
            {
                return $this->mainDomain;
            }
        };
        $user->username = $this->username;
        $user->details = $details;

        return $user;
    }

    private function dind(ModelsUser $user, ?SystemProject $project = null, ?RecordingSystem $host = null): Dind&MockInterface
    {
        $host ??= $this->host([]);
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('username')->andReturn($this->username);
        $dind->shouldReceive('project')->andReturn($project ?? Mockery::mock(SystemProject::class));
        $dind->shouldReceive('system')->andReturn($host);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));
        $dind->shouldReceive('homeDirPath')->andReturn($this->home);
        $dind->shouldReceive('userAppDirPath')->andReturn($this->home.'/project');
        $dind->shouldReceive('userAppComposeProjectName')->andReturn('project');
        $dind->shouldReceive('accountDaemon')->andReturnUsing(fn (): AccountDaemon => new AccountDaemon($dind));

        return $dind;
    }

    private function startLog(): void
    {
        $this->deploy = DeployLogger::start($this->username);
    }

    /** @return list<array{string, string}> */
    private function lines(): array
    {
        return array_map(
            static fn (array $line): array => [$line['level'], $line['msg']],
            $this->deploy?->entries() ?? [],
        );
    }
}
