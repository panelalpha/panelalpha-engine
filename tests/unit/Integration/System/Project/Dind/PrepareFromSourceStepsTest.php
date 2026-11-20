<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\AppCredentialDelivery;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\PrepareFromSource;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** Preparing a checkout: what the deploy log says, what is frozen, and what refuses the deploy. */
class PrepareFromSourceStepsTest extends TestCase
{
    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private string $username = '';

    private string $home = '';

    private ?DeployLogger $deploy = null;

    /** @var array<string, mixed> */
    private array $frozen = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['deploy.step_idle_timeout' => 0]);
        $this->username = 'pfs'.bin2hex(random_bytes(3));
        $this->home = sys_get_temp_dir().'/pa-pfs-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/project', 0755, true);
        $routing = Mockery::mock(ProjectPublicRouting::class);
        $routing->shouldReceive('prepare', 'routePorts');
        $this->app->instance(ProjectPublicRouting::class, $routing);
    }

    protected function tearDown(): void
    {
        app(DeployPlanContext::class)->clear();
        $this->deploy = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        (new Process(['rm', '-rf', $this->home]))->run();
        parent::tearDown();
    }

    public function test_the_stages_a_deploy_request_replaced_are_said(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->write('index.html', '<!doctype html><title>Shop</title>');
        app(DeployPlanContext::class)->set(DeployPlan::fromArray(['build' => [['run' => 'make assets']]]));

        $this->prepare();

        $this->assertContains('Deploy request replaced these stages: build', $this->lines());
        $this->assertSame('static', $this->frozen['deploy_strategy']);
    }

    public function test_an_empty_checkout_is_refused_and_the_refusal_is_said(): void
    {
        $this->deploy = DeployLogger::start($this->username);

        try {
            $this->prepare();
            $this->fail('an empty checkout must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Project directory is empty; nothing to deploy.', $e->getMessage());
        }

        $this->assertContains('Project directory is empty; nothing to deploy.', $this->lines());
        $this->assertSame([], $this->frozen);
    }

    public function test_a_git_checkout_whose_head_cannot_be_read_is_refused(): void
    {
        $this->write('index.html', '<!doctype html><title>Shop</title>');

        try {
            $this->prepare(['git_repo' => 'https://github.com/example/shop.git'], ['rev-parse' => FakeProcess::failed("fatal: ambiguous argument 'HEAD'", 128)]);
            $this->fail('an unreadable HEAD must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Git HEAD is not readable after clone; repository may be empty or corrupt.', $e->getMessage());
        }
    }

    public function test_a_git_checkout_freezes_its_head_commit(): void
    {
        $this->write('index.html', '<!doctype html><title>Shop</title>');

        $this->prepare(['git_repo' => 'https://github.com/example/shop.git'], ['rev-parse' => FakeProcess::ok(self::COMMIT."\n")]);

        $this->assertSame('git', $this->frozen['deploy_source']);
        $this->assertSame(self::COMMIT, $this->frozen['git_commit']);
    }

    public function test_a_database_the_strategy_cannot_provision_is_warned_about(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->write('.panelalpha/panelalpha.yaml', "id: demo\nlabel: Demo\nstrategy: static\ndatabase: mysql\n");
        $this->write('index.html', '<!doctype html><title>Shop</title>');

        $this->prepare();

        $this->assertContains(
            'The recipe declares `database: mysql`, but strategy static does not provision one; this deploy gets no database and no DB_* variables.',
            $this->lines(),
        );
    }

    public function test_a_project_no_recipe_matched_still_deploys_on_the_fallback(): void
    {
        $this->write('notes.txt', "just some notes\n");

        $this->prepare();

        $this->assertSame('fallback', $this->frozen['deploy_strategy']);
    }

    public function test_the_welcome_page_is_removed_once_the_project_has_files_of_its_own(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $this->write('index.html', '<!doctype html><title>'.PlaceholderPage::WELCOME_TITLE.'</title>');
        $this->write('about.html', '<!doctype html><title>About</title>');
        $host = $this->host([]);

        $this->prepare([], [], $host);

        $this->assertContains(Privileged::files()->removeIfExists($this->home.'/project/index.html'), $host->commands);
        $this->assertContains('Removed the placeholder page: the project has files of its own', $this->lines());
    }

    public function test_a_precheck_the_request_adds_runs_as_the_account_and_is_removed_after(): void
    {
        $host = $this->host([]);

        $this->preCheck($host);

        $script = $this->home.'/panelalpha-before-clone-validation.sh';
        $this->assertNotNull($this->commandWith($host, 'exec bash "$0" 1>&2'));
        $this->assertNotNull($this->commandWith($host, $script));
        $this->assertSame(['rm', '-f', $script], array_slice((array) end($host->commands), -3));
    }

    public function test_a_precheck_that_refuses_the_deploy_is_marked_rejected(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $host = $this->host(['exec bash "$0" 1>&2' => FakeProcess::failed('composer.json is missing')]);

        try {
            $this->preCheck($host);
            $this->fail('the refused precheck must surface');
        } catch (\Exception $e) {
            $this->assertStringContainsString('composer.json is missing', $e->getMessage());
        }

        $this->assertTrue($this->deploy->readLatest()['precheck_rejected'] ?? false);
        $this->assertNotNull($this->commandWith($host, 'rm'));
    }

    public function test_a_cancel_during_the_precheck_is_not_marked_rejected(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        DeployLogger::requestCancel($this->username);

        try {
            $this->preCheck($this->host([]));
            $this->fail('the cancel must surface');
        } catch (DeployCancelledException) {
        }

        $this->assertFalse($this->deploy->readLatest()['precheck_rejected'] ?? false);
    }

    private function preCheck(RecordingSystem $host): void
    {
        app(DeployPlanContext::class)->set(DeployPlan::fromArray(['precheck' => [['run' => 'test -f composer.json']]]));
        $dind = $this->dind($this->user(['git_repo' => 'https://github.com/example/shop.git']), $host);

        (new PrepareFromSource($dind))->preCheck();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, Process>  $answers
     */
    private function prepare(array $details = [], array $answers = [], ?RecordingSystem $host = null): void
    {
        $dind = $this->dind($this->user($details), $host ?? $this->host($answers));

        (new PrepareFromSource($dind))->prepare();
    }

    /** @param  array<string, mixed>  $details */
    private function user(array $details): ModelsUser
    {
        $user = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }

            public function getMainDomain(): ?DomainModel
            {
                return null;
            }
        };
        $user->username = $this->username;
        $user->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000] + $details);

        return $user;
    }

    private function dind(ModelsUser $user, RecordingSystem $host): Dind&MockInterface
    {
        $strategy = Mockery::mock(DeployStrategy::class);
        $strategy->shouldReceive('bootstrap', 'apply', 'dropUndefinedOverrideServices', 'installRailsHostInitializer', 'keepEngineFilesOutOfBuildContext');
        $credentials = Mockery::mock(AppCredentialDelivery::class);
        $credentials->shouldReceive('deliver');
        $networking = Mockery::mock(Networking::class);
        $networking->shouldReceive('detectAndCreateProxyRules');

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('username')->andReturn($this->username);
        $dind->shouldReceive('userAppDirPath')->andReturn($this->home.'/project');
        $dind->shouldReceive('homeDirPath')->andReturn($this->home);
        $dind->shouldReceive('system')->andReturn($host);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));
        $dind->shouldReceive('appConfig')->andReturn(null);
        $dind->shouldReceive('appCredentials')->andReturn($credentials);
        $dind->shouldReceive('strategy')->andReturn($strategy);
        $dind->shouldReceive('networking')->andReturn($networking);
        $dind->shouldReceive('applyProjectEnvVars');
        $dind->shouldReceive('freezeDeploySnapshot')->andReturnUsing(function (array $snapshot): void {
            $this->frozen = $snapshot;
        });

        return $dind;
    }

    /** @param  array<string, Process>  $answers  by a fragment of the command line; anything else succeeds */
    private function host(array $answers): RecordingSystem
    {
        $host = new class extends RecordingSystem
        {
            /** @var array<string, Process> */
            public array $answers = [];

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                foreach ($this->answers as $fragment => $process) {
                    if (str_contains($line, $fragment)) {
                        return $process;
                    }
                }

                return FakeProcess::ok();
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

    private function commandWith(RecordingSystem $host, string $fragment): ?string
    {
        foreach ($host->commands as $cmd) {
            $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
            if (str_contains($line, $fragment)) {
                return $line;
            }
        }

        return null;
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->home.'/project/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /** @return list<string> */
    private function lines(): array
    {
        return array_map(static fn (array $line): string => (string) $line['msg'], $this->deploy?->entries() ?? []);
    }
}
