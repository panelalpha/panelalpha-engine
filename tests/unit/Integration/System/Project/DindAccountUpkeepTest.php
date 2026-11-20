<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User as ModelsUser;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** The account-level upkeep a DinD project does on its host files and in its checkout, against a faked host. */
class DindAccountUpkeepTest extends TestCase
{
    private string $username = '';

    private string $root = '';

    private ?DeployLogger $deploy = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'upkeep'.bin2hex(random_bytes(3));
        $this->root = sys_get_temp_dir().'/pa-upkeep-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deploy = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_the_account_container_is_the_default_service(): void
    {
        $this->assertSame('dind', $this->dind($this->host([]))->defaultServiceName());
    }

    public function test_an_egress_guard_turned_off_is_left_alone(): void
    {
        config(['env.DIND_EGRESS_GUARD' => false]);
        $host = $this->host([]);

        $this->dind($host)->rewriteEgressGuard();

        $this->assertSame([], $host->commands);
    }

    public function test_no_egress_guard_is_written_before_the_account_directory_exists(): void
    {
        config(['env.DIND_EGRESS_GUARD' => true]);
        $host = $this->host(['files.isDirectory' => FakeProcess::failed()]);
        $dind = $this->dind($host);

        $dind->rewriteEgressGuard();

        $this->assertSame([Privileged::files()->isDirectory($dind->projectDirPath().'/entrypoint.d')], $host->commands);
    }

    public function test_daemon_json_is_overwritten_in_place_but_never_through_a_symlink(): void
    {
        $plain = $this->host(['files.isSymlink' => FakeProcess::failed()]);
        $linked = $this->host(['files.isSymlink' => FakeProcess::ok()]);

        $this->assertTrue($this->dind($plain)->rewriteDaemonJsonInPlace());
        $this->assertFalse($this->dind($linked)->rewriteDaemonJsonInPlace());

        $this->assertSame(['files.isSymlink', 'files.copy'], $this->actions($plain));
        $this->assertSame(['files.isSymlink'], $this->actions($linked));
    }

    public function test_containers_the_account_daemon_cannot_list_are_none(): void
    {
        $host = $this->host(['account-docker.listContainers' => FakeProcess::failed('daemon down')]);

        $this->assertSame([], $this->dind($host)->getContainers());
    }

    public function test_the_memory_limit_is_written_and_put_on_a_running_account(): void
    {
        $host = $this->host(['compose.runningServices' => FakeProcess::ok("dind\n")]);
        $dind = $this->dind($host);
        mkdir(dirname($dind->composeFilePath()), 0755, true);
        file_put_contents($dind->composeFilePath(), "services:\n  dind:\n    container_name: {$this->username}\n    image: docker:dind\n");

        $dind->applyMemoryLimit();

        $this->assertStringContainsString('mem_limit:', (string) file_get_contents($dind->composeFilePath()));
        $this->assertContains('docker.limitMemory', $this->actions($host));
    }

    public function test_each_tracked_file_an_app_config_overwrites_is_said(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $host = $this->host([
            '--is-inside-work-tree' => FakeProcess::ok("true\n"),
            'ls-files' => FakeProcess::ok("config/app.php\n"),
        ]);

        $this->dind($host)->noteAppConfigOverwritesTracked(['/config/app.php', 'config/app.php', '.env']);

        $this->assertSame(['App config overwrites tracked file config/app.php'], $this->lines());
    }

    public function test_nothing_is_said_when_the_checkout_is_no_repository_or_git_fails(): void
    {
        $this->deploy = DeployLogger::start($this->username);
        $noRepository = $this->host(['--is-inside-work-tree' => FakeProcess::failed('fatal: not a git repository', 128)]);
        $failing = $this->host([
            '--is-inside-work-tree' => FakeProcess::ok("true\n"),
            'ls-files' => FakeProcess::failed('fatal: index file corrupt', 128),
        ]);

        $this->dind($noRepository)->noteAppConfigOverwritesTracked(['config/app.php']);
        $this->dind($failing)->noteAppConfigOverwritesTracked(['config/app.php']);

        $this->assertSame([], $this->lines());
        $this->assertNotNull($this->commandWith($failing, 'ls-files'));
        $this->assertNull($this->commandWith($noRepository, 'ls-files'));
    }

    public function test_a_project_without_an_app_config_installs_no_snippets(): void
    {
        $host = $this->host(['files.isFile' => FakeProcess::failed(), 'files.exists' => FakeProcess::failed(), 'files.isDirectory' => FakeProcess::failed()]);

        $this->dind($host)->installFileSnippets();

        $this->assertNotContains('files.writeFileReplacingPath', $this->actions($host));
        $this->assertNull($this->commandWith($host, 'mkdir'));
    }

    private function dind(RecordingSystem $host): Dind
    {
        $model = new ModelsUser;
        $model->username = $this->username;
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);
        $runtime = (new ProjectAggregate($host, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** @param  array<string, Process>  $answers  by broker action or a fragment of the command line; anything else succeeds */
    private function host(array $answers): RecordingSystem
    {
        $host = new class extends RecordingSystem
        {
            /** @var array<string, Process> */
            public array $answers = [];

            public string $root = '';

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                $action = is_array($cmd) ? LocalRoot::action($cmd) : null;
                if ($action === 'files.copy') {
                    [$from, $to] = LocalRoot::values($cmd);
                    @copy($from, $to);
                }
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                foreach ($this->answers as $key => $process) {
                    if ($key === $action || str_contains($line, $key)) {
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
        $host->root = $this->root;

        return $host;
    }

    /** @return list<?string> */
    private function actions(RecordingSystem $host): array
    {
        return array_values(array_filter(array_map(
            static fn ($cmd): ?string => is_array($cmd) ? LocalRoot::action($cmd) : null,
            $host->commands,
        )));
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

    /** @return list<string> */
    private function lines(): array
    {
        return array_map(static fn (array $line): string => (string) $line['msg'], $this->deploy?->entries() ?? []);
    }
}
