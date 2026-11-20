<?php

namespace Tests\Integration\System\Project;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Filesystem\Filesystem as LocalFilesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\Support\TestableGitRepository;
use Tests\TestCase;

/**
 * The deploy ingest's checkout: where it is, the clone of the configured remote with what the app config asks of it,
 * and the submodule fetch when git cannot answer or the deploy is cancelled.
 */
class DindGitRepositoryEdgesTest extends TestCase
{
    private string $root;

    /** @var list<string> git argv lines the account's container was asked to run */
    private array $containerGit = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir() . '/pa-dind-git-edges-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir(), 0755, true);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs('alice');
        (new LocalFilesystem())->remove($this->root);
        parent::tearDown();
    }

    public function test_a_project_dir_under_the_home_keeps_its_path_and_any_other_is_the_project(): void
    {
        $dind = $this->dind();

        $nested = GitRepository::forProjectDir($dind, $this->root . '/home/alice/sites/blog');
        $outside = GitRepository::forProjectDir($dind, '/srv/elsewhere');

        $this->assertSame('sites/blog', $nested->pathKey());
        $this->assertSame($this->root . '/home/alice/sites/blog', $nested->absolutePath());
        $this->assertSame('project', $outside->pathKey());
        $this->assertSame($this->projectDir(), $outside->absolutePath());
    }

    public function test_an_absolute_path_is_refused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Project-relative paths only.'));
        new GitRepository($this->dind(), '/etc');
    }

    public function test_a_clone_of_a_branch_whose_app_config_asks_for_the_full_history_fetches_it(): void
    {
        $commands = [];
        $git = new TestableGitRepository($this->homeDind(['git_branch' => 'main']), $this->cloneWriting(
            ['panelalpha.yaml' => "git:\n  history: full\n"],
            $commands,
        ));
        $logger = DeployLogger::start('alice');

        $git->cloneConfiguredRepository();

        $lines = array_column($logger->read()['lines'], 'msg');
        $logger->finish(DeployLogger::STATUS_SUCCESS);
        $this->assertContains('Cloning repository https://github.com/org/panel.git (branch: main)', $lines);
        $this->assertContains('Fetching the full history and tags (the recipe sets git: {history: full})', $lines);
        $this->assertNotEmpty(array_filter($commands, fn (string $c): bool => str_contains($c, 'fetch --unshallow --tags origin')));
    }

    public function test_a_malformed_app_config_does_not_deepen_the_clone(): void
    {
        $commands = [];
        $git = new TestableGitRepository($this->homeDind(), $this->cloneWriting(
            ['panelalpha.yaml' => "git:\n  history: sometimes\n"],
            $commands,
        ));

        $git->cloneConfiguredRepository();

        $this->assertNotEmpty(array_filter($commands, fn (string $c): bool => str_contains($c, 'clone --depth=1')));
        $this->assertEmpty(array_filter($commands, fn (string $c): bool => str_contains($c, ' fetch ')));
    }

    public function test_a_malformed_app_config_does_not_limit_the_submodules(): void
    {
        file_put_contents($this->projectDir() . '/.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n");
        file_put_contents($this->projectDir() . '/panelalpha.yaml', "git:\n  submodules: lib\n  colour: blue\n");
        $commands = [];
        $git = new TestableGitRepository($this->dind(), static function (array $command) use (&$commands): string {
            $commands[] = implode(' ', $command);

            return "true\n";
        });

        $git->fetchSubmodules('https://github.com/org/panel.git', null);

        $submodule = array_values(array_filter($commands, fn (string $c): bool => str_contains($c, 'submodule update')));
        $this->assertCount(1, $submodule);
        $this->assertStringEndsWith('submodule update --init --recursive --depth=1', $submodule[0]);
    }

    public function test_a_redeploy_fails_on_a_submodule_when_git_cannot_read_what_head_declares(): void
    {
        file_put_contents($this->projectDir() . '/.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n");
        $git = new TestableGitRepository($this->dind(), static function (array $command): string {
            $joined = implode(' ', $command);

            return match (true) {
                str_contains($joined, 'submodule update') => throw new GitException("fatal: clone of 'https://github.com/org/lib.git' into submodule path 'lib' failed", 400),
                str_contains($joined, 'config --blob HEAD:.gitmodules') => throw new GitException('fatal: bad object HEAD', 128),
                default => "true\n",
            };
        });

        try {
            $git->fetchSubmodules('https://github.com/org/panel.git', null, keepPrevious: true);
            $this->fail('A submodule git could not judge was kept.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $e->getMessage());
            $this->assertStringContainsString("submodule path 'lib' failed", $e->getMessage());
        }
    }

    public function test_a_cancelled_deploy_stops_the_submodule_fetch_in_the_container(): void
    {
        file_put_contents($this->projectDir() . '/.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n");
        $git = new GitRepository($this->dind());
        $logger = DeployLogger::start('alice');
        DeployLogger::requestCancel('alice');

        try {
            $git->fetchSubmodules('https://github.com/org/panel.git', null);
            $this->fail('A cancelled deploy fetched its submodules.');
        } catch (DeployCancelledException $e) {
            $this->assertSame('Deployment cancelled by user', $e->getMessage());
        } finally {
            $logger->finish(DeployLogger::STATUS_CANCELLED);
        }

        $this->assertNotEmpty(array_filter($this->containerGit, fn (string $c): bool => str_contains($c, 'rev-parse')));
        $this->assertEmpty(array_filter($this->containerGit, fn (string $c): bool => str_contains($c, 'submodule')));
    }

    /**
     * Git as a fake that writes $files into the clone's target, under the temporary root, when it clones.
     *
     * @param  array<string, string>  $files
     * @param  list<string>  $commands
     * @return callable(list<string>): string
     */
    private function cloneWriting(array $files, array &$commands): callable
    {
        $root = $this->root;

        return static function (array $command) use ($files, &$commands, $root): string {
            $joined = implode(' ', $command);
            $commands[] = $joined;
            if (in_array('clone', $command, true)) {
                foreach ($files as $name => $content) {
                    file_put_contents($root . end($command) . '/' . $name, $content);
                }

                return '';
            }

            return match (true) {
                str_contains($joined, '--is-shallow-repository') => "true\n",
                str_contains($joined, 'branch --show-current') => "main\n",
                str_contains($joined, 'config --get remote.origin.url') => "https://github.com/org/panel.git\n",
                str_contains($joined, 'rev-parse --is-inside-work-tree') => "true\n",
                default => '',
            };
        };
    }

    private function projectDir(): string
    {
        return $this->root . '/home/alice/project';
    }

    /** @param array<string, mixed> $details */
    private function dind(array $details = []): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'git_repo' => 'https://github.com/org/panel.git', ...$details]);
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /**
     * A project at /home/alice, as the tree's guards require: the host records every broker request and answers
     * none, and the app config is read from the temporary root.
     *
     * @param array<string, mixed> $details
     */
    private function homeDind(array $details = []): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'git_repo' => 'https://github.com/org/panel.git', ...$details]);
        $system = new class ($this->root) extends System {
            public function __construct(private string $root)
            {
            }

            public function homesDirPath(): string
            {
                return '/home';
            }

            /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::failed('no process is started in this test');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->root) extends Filesystem {
                    public function __construct(System $system, private string $root)
                    {
                        parent::__construct($system);
                    }

                    public function fileExists(string $path): bool
                    {
                        return is_file($this->root . $path);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($this->root . $path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) file_get_contents($this->root . $path);
                    }
                };
            }
        };
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** Broker requests answered locally against the temporary home; git in the container answers "true". */
    private function system(): System
    {
        $containerGit = &$this->containerGit;

        return new class ($this->root, $containerGit) extends System {
            /** @param list<string> $containerGit */
            public function __construct(private string $root, private array &$containerGit)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::failed('no process is started in this test');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (is_array($cmd) && str_contains(implode(' ', $cmd), "'git' ")) {
                    $this->containerGit[] = implode(' ', $cmd);

                    return "true\n";
                }

                return parent::exec(is_array($cmd) ? LocalRoot::argv($cmd) : $cmd, $this->withLocalHomes($env), $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return parent::runProcess(is_array($cmd) ? LocalRoot::argv($cmd) : $cmd, $this->withLocalHomes($env), $timeout);
            }

            /**
             * @param array<string, string> $env
             * @return array<string, string>
             */
            private function withLocalHomes(array $env): array
            {
                return [...$env, 'PA_ROOT_LOCAL_ROOTS' => json_encode(['homes' => $this->root . '/home'], JSON_THROW_ON_ERROR)];
            }
        };
    }
}
