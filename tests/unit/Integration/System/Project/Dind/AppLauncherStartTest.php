<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppCertificate;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ComposePsFixture;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * start() from the pulls before `up` to the verdict after it, against a scripted account:
 * gates that exited, helpers let go, failed dependencies, published builds and the reroute.
 */
class AppLauncherStartTest extends TestCase
{
    private const APP = '{"Name":"project-app-1","Service":"app","State":"running","ExitCode":0}';

    private string $username = '';

    private ?DeployLogger $logger = null;

    /** @var list<string> compose subcommand of each process run, in order */
    private array $ran = [];

    /** @var array<string, string> compose subcommand => the shell script it runs as; exit 0 otherwise */
    private array $scripts = [];

    /** @var list<string> `compose ps --all` answers, one per ask, the last repeated */
    private array $ps = [''];

    private int $psAsked = 0;

    /** What the app's stack lists when asked for its running containers. */
    private string $running = '';

    /** @var ?array<string, mixed> `compose config --format json`; null when it cannot be read */
    private ?array $config = null;

    private ?string $runFile = null;

    private string $logs = '';

    private bool $logsFail = false;

    /** @var array<string, string> container => what it printed */
    private array $tails = [];

    /** @var array<string, array<string, mixed>> container => its inspect */
    private array $inspects = [];

    private bool $daemonFails = false;

    /** @var list<?array<string, mixed>> what each health report returns, in order */
    private array $reports = [];

    private int $reported = 0;

    /** @var list<int> ports the site was routed to */
    private array $routedTo = [];

    /** @var list<string> inner Docker and host compile calls */
    private array $calls = [];

    private string $portsFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'start-' . bin2hex(random_bytes(6));
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    protected function tearDown(): void
    {
        $this->logger = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        if ($this->portsFile !== '' && is_file($this->portsFile)) {
            unlink($this->portsFile);
        }
        parent::tearDown();
    }

    public function test_a_gate_that_exited_non_zero_fails_the_deploy_after_up(): void
    {
        $this->deploying();
        $this->runFile = self::gateRunFile('"no"');
        $this->ps = [self::APP . "\n" . '{"Name":"project-ready-1","Service":"ready","State":"exited","ExitCode":1}'];
        $this->logs = "project-ready-1  | 2026-11-20T08:00:00Z refusing: authentication is off\n\n";

        $result = $this->launcher()->start();

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('A service exited non-zero after the stack came up: ready (exit 1)', $result['stderr']);
        $this->assertSame(['up -d --remove-orphans --build'], $this->ran);
        $lines = $this->lines();
        $this->assertContains('[info] Starting application (docker compose up -d)', $lines);
        $this->assertContains('[info] Service ready exited with code 1; the deploy is not successful', $lines);
        $this->assertContains('[info] Container output at the point of failure:', $lines);
        $this->assertContains('[info] project-ready-1  | 2026-11-20T08:00:00Z refusing: authentication is off', $lines);
        $this->assertSame(0, $this->reported, 'a failed gate is not followed by the health verdict');
    }

    public function test_a_failed_gate_whose_containers_output_cannot_be_read_still_fails_the_deploy(): void
    {
        $this->deploying();
        $this->runFile = self::gateRunFile('"no"');
        $this->ps = [self::APP . "\n" . '{"Name":"project-ready-1","Service":"ready","State":"exited","ExitCode":4}'];
        $this->logsFail = true;

        $result = $this->launcher()->start();

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('A service exited non-zero after the stack came up: ready (exit 4)', $result['stderr']);
        $this->assertNotContains('[info] Container output at the point of failure:', $this->lines());
    }

    public function test_a_helper_that_exited_is_said_and_the_deploy_goes_on(): void
    {
        $this->deploying();
        $this->runFile = self::gateRunFile(null);
        $this->ps = [self::APP . "\n" . '{"Name":"project-ready-1","Service":"ready","State":"exited","ExitCode":2}'];

        $result = $this->launcher()->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertContains(
            '[warn] Service ready exited with code 2; nothing depends on it, it publishes no port and has no restart policy, so it stays stopped',
            $this->lines()
        );
        $this->assertSame(1, $this->reported);
    }

    public function test_a_one_shot_still_running_is_waited_for_before_its_exit_code_is_read(): void
    {
        $this->deploying();
        $this->runFile = self::gateRunFile('"no"');
        $this->ps = [
            self::APP . "\n" . '{"Name":"project-ready-1","Service":"ready","State":"running","ExitCode":0}',
            self::APP . "\n" . '{"Name":"project-ready-1","Service":"ready","State":"exited","ExitCode":0}',
        ];

        $result = $this->launcher()->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(2, $this->psAsked);
        $this->assertContains('[info] Waiting for one-shot services to finish: ready', $this->lines());
    }

    public function test_a_stack_that_cannot_be_listed_is_not_judged_a_failed_gate(): void
    {
        $this->runFile = self::gateRunFile('"no"');
        $this->daemonFails = true;

        $result = $this->launcher()->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(['up -d --remove-orphans --build'], $this->ran);
    }

    public function test_an_up_that_failed_on_a_dependency_leads_with_what_it_printed(): void
    {
        $this->deploying();
        $this->scripts['up'] = 'echo "dependency failed to start: container project-db-1 is unhealthy" >&2; exit 1';
        $this->tails['project-db-1'] = "mysqld: Access denied for user 'app'\n";
        $this->inspects['project-db-1'] = ['State' => ['Health' => ['Status' => 'unhealthy', 'FailingStreak' => 3, 'Log' => [
            ['Start' => '', 'End' => '', 'ExitCode' => 1, 'Output' => 'ERROR 1045 (28000): Access denied'],
        ]]]];

        $result = $this->launcher()->start();

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringStartsWith(
            "error: service project-db-1 did not start (unhealthy) [health check: ERROR 1045 (28000): Access denied]: mysqld: Access denied for user 'app'\n",
            $result['stderr']
        );
        $this->assertStringEndsWith("dependency failed to start: container project-db-1 is unhealthy\n", $result['stderr']);
        $this->assertContains(
            "[info] error: service project-db-1 did not start (unhealthy) [health check: ERROR 1045 (28000): Access denied]: mysqld: Access denied for user 'app'",
            $this->lines()
        );
    }

    public function test_a_failed_dependency_whose_container_cannot_be_read_is_still_named(): void
    {
        $this->scripts['up'] = 'echo "dependency failed to start: container project-db-1 is unhealthy" >&2; exit 1';
        $this->daemonFails = true;

        $result = $this->launcher()->start();

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringStartsWith('error: service project-db-1 did not start (unhealthy)', $result['stderr']);
    }

    public function test_an_image_the_new_version_needs_that_cannot_be_pulled_keeps_the_running_one(): void
    {
        $this->running = self::APP;
        $this->config = ['name' => 'project', 'services' => [
            'app' => ['build' => ['context' => '.']],
            'cache' => ['image' => 'redis:7'],
        ]];
        $this->scripts['pull'] = 'echo "manifest for redis:7 not found" >&2; exit 18';

        $result = $this->launcher()->start();

        $this->assertSame(['pull --policy missing cache'], $this->ran);
        $this->assertSame(18, $result['exit_code']);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertSame(
            "An image the new version needs could not be pulled; the previous version is still serving.\nmanifest for redis:7 not found\n",
            $result['stderr']
        );
    }

    public function test_the_images_pulled_while_the_running_version_serves_are_followed_by_up(): void
    {
        $this->running = self::APP;
        $this->config = ['name' => 'project', 'services' => ['cache' => ['image' => 'redis:7']]];

        $result = $this->launcher()->start();

        $this->assertSame(['pull --policy missing cache', 'up -d --remove-orphans --build'], $this->ran);
        $this->assertSame(0, $result['exit_code']);
    }

    public function test_a_published_build_is_pulled_and_started_without_building(): void
    {
        $this->config = ['name' => 'project', 'services' => ['app' => [
            'build' => ['context' => '.'], 'image' => 'ghcr.io/acme/app:1.4', 'pull_policy' => 'missing',
        ]]];

        $result = $this->launcher()->start();

        $this->assertSame(['pull app', 'up -d --remove-orphans --no-build'], $this->ran);
        $this->assertSame(0, $result['exit_code']);
    }

    public function test_a_published_build_the_registry_lacks_is_built_from_the_project(): void
    {
        $this->deploying();
        $this->config = ['name' => 'project', 'services' => ['app' => [
            'build' => ['context' => '.'], 'image' => 'ghcr.io/acme/app:branch', 'pull_policy' => 'missing',
        ]]];
        $this->scripts['pull'] = 'exit 1';

        $this->launcher()->start();

        $this->assertSame(['pull app', 'up -d --remove-orphans --build'], $this->ran);
        $lines = $this->lines();
        $this->assertContains('[info] Pulling the published image of app (docker compose pull)', $lines);
        $this->assertContains('[info] No published image of app could be pulled; building it from the project', $lines);
    }

    public function test_the_site_moves_to_the_one_other_port_that_answered_and_the_verdict_is_asked_again(): void
    {
        $this->deploying();
        $this->portsFile = (string) tempnam(sys_get_temp_dir(), 'ports');
        file_put_contents($this->portsFile, "services:\n  cabernet:\n    image: ghcr.io/cabernetwork/cabernet\n    ports:\n      - \"6077:6077\"\n      - \"5004:5004\"\n");
        $this->reports = [
            ['healthy' => false, 'ports' => [['port' => 5004, 'http_code' => 501], ['port' => 6077, 'http_code' => 200]]],
            ['healthy' => true, 'ports' => [['port' => 6077, 'http_code' => 200]]],
        ];

        $result = $this->launcher(['app_port' => 5004])->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([6077], $this->routedTo);
        $this->assertSame(2, $this->reported);
        $this->assertContains('[info] Routed the site to 6077 instead of 5004: 5004 answered 501, 6077 answered 200', $this->lines());
    }

    public function test_a_site_whose_ports_cannot_be_read_stays_where_it_is(): void
    {
        $this->deploying();
        $this->portsFile = (string) tempnam(sys_get_temp_dir(), 'ports');
        file_put_contents($this->portsFile, "services:\n  cabernet:\n    image: ghcr.io/cabernetwork/cabernet\n    ports:\n      - \"6077:6077\"\n      - \"5004:5004\"\n");
        $this->reports = [['healthy' => false, 'ports' => [['port' => 5004, 'http_code' => 501], ['port' => 6077, 'http_code' => 200]]]];

        $this->launcher(['app_port' => 5004], environmentFails: true)->start();

        $this->assertSame([], $this->routedTo);
        $this->assertSame(1, $this->reported);
    }

    public function test_a_restart_loop_the_health_check_saw_has_the_containers_output_recorded(): void
    {
        $this->deploying();
        $this->reports = [['healthy' => false, 'ports' => []]];
        $this->logs = "project-app-1  | panic: no config\n";

        $result = $this->launcher([AppHealth::DETAIL_CHECKS => [['id' => AppHealth::CHECK_RESTART_LOOPING]]])->start();

        $this->assertSame(0, $result['exit_code']);
        $lines = $this->lines();
        $this->assertContains('[info] Container output at the point of failure:', $lines);
        $this->assertContains('[info] project-app-1  | panic: no config', $lines);
    }

    public function test_a_standalone_node_app_is_compiled_on_the_host_and_started_on_the_node_image(): void
    {
        $result = $this->launcher(['deploy_strategy' => 'nuxt'])->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(['hostCompile', 'ensureCurrentImage', 'preloadComposeImages'], $this->calls);
        $this->assertSame(['up -d --remove-orphans --force-recreate --no-build'], $this->ran);
    }

    public function test_a_compose_project_with_an_unreadable_run_file_starts_as_written(): void
    {
        $this->runFile = null;

        $result = $this->launcher(['deploy_strategy' => 'compose'], runFileFails: true)->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(['up -d --remove-orphans --build'], $this->ran);
    }

    private function deploying(): void
    {
        $this->logger = DeployLogger::start($this->username);
        $this->logger->stage(DeployLogger::STAGE_RUNNING);
    }

    private static function gateRunFile(?string $restart): string
    {
        $policy = $restart === null ? '' : "\n    restart: {$restart}";

        return "services:\n  app:\n    image: acme/app\n    ports: ['3000:3000']\n    restart: unless-stopped\n"
            . "  ready:\n    image: acme/ready{$policy}\n";
    }

    /** @return list<string> the deploy log, as "[level] message" */
    private function lines(): array
    {
        return array_map(
            static fn (array $line): string => "[{$line['level']}] {$line['msg']}",
            DeployLogger::forDeploy($this->username, (string) $this->logger?->getDeployId())->tail(500)
        );
    }

    /** @param list<string> $argv */
    private function answer(array $argv): string
    {
        if (preg_grep('/^build-guard/', $argv)) {
            return '';
        }
        $sub = self::composeSubcommand($argv);
        if ($sub !== []) {
            return match ($sub[0]) {
                'config' => $this->config === null ? '' : (string) json_encode($this->config),
                'logs' => $this->logsFail ? throw new \RuntimeException('compose logs timed out') : $this->logs,
                default => '',
            };
        }
        if ($this->daemonFails) {
            throw new \RuntimeException('docker daemon not reachable');
        }
        $values = LocalRoot::values($argv);

        return match (LocalRoot::action($argv)) {
            'account-docker.listContainers' => ComposePsFixture::listing($this->listed($values)),
            'account-docker.containers' => $this->inspected(array_slice($values, 1)),
            'account-docker.logTail' => $this->tails[$values[1] ?? ''] ?? '',
            default => throw new \RuntimeException('unexpected command ' . implode(' ', $argv)),
        };
    }

    /** @param list<string> $values */
    private function listed(array $values): string
    {
        if (($values[1] ?? null) !== 'all' || in_array('com.docker.compose.oneoff=False', $values, true)) {
            return $this->running;
        }
        $answer = $this->ps[min($this->psAsked, count($this->ps) - 1)];
        $this->psAsked++;

        return $answer;
    }

    /** @param list<string> $ids */
    private function inspected(array $ids): string
    {
        $current = $this->ps[min(max($this->psAsked - 1, 0), count($this->ps) - 1)];
        $rows = json_decode(ComposePsFixture::inspect($current, $ids), true);

        return (string) json_encode(array_map(
            fn (string $id, ?array $row): ?array => $this->inspects[$id] ?? $row,
            $ids,
            is_array($rows) ? $rows : []
        ));
    }

    /** @param list<string> $argv */
    private function process(array $argv): Process
    {
        $sub = self::composeSubcommand($argv);
        $this->ran[] = implode(' ', $sub);
        $process = new Process(['sh', '-c', $this->scripts[$sub[0] ?? ''] ?? 'exit 0']);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    private static function composeSubcommand(array $argv): array
    {
        $inner = LocalRoot::composeCommand($argv);
        $at = array_search('--', $inner, true);

        return $at === false ? [] : array_values(array_slice($inner, (int) $at + 1));
    }

    /** @param array<string, mixed> $details */
    private function launcher(array $details = [], bool $environmentFails = false, bool $runFileFails = false): AppLauncher
    {
        $filesystem = $this->createStub(Filesystem::class);
        if ($runFileFails) {
            $filesystem->method('fileGetContents')->willThrowException(new \RuntimeException('No such file'));
        } else {
            $filesystem->method('fileGetContents')->willReturnCallback(fn (): string => $this->runFile
                ?? "services:\n  app:\n    build: { context: . }\n    ports: ['3000:3000']\n    restart: unless-stopped\n");
        }
        $system = new class (fn (array $argv): string => $this->answer($argv), fn (array $argv): Process => $this->process($argv), $filesystem) extends System {
            public function __construct(private \Closure $answer, private \Closure $process, private Filesystem $files)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return ($this->answer)((array) $cmd);
            }

            public function filesystem(): Filesystem
            {
                return $this->files;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return ($this->process)((array) $cmd);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return ($this->process)((array) $cmd);
            }
        };

        $calls = &$this->calls;
        $inner = $this->createStub(InnerDocker::class);
        foreach (['ensureCurrentImage', 'preloadComposeImages'] as $method) {
            $inner->method($method)->willReturnCallback(function () use (&$calls, $method): void {
                $calls[] = $method;
            });
        }
        $hostCompile = $this->createStub(HostCompile::class);
        $hostCompile->method('run')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'hostCompile';
        });

        $health = $this->createStub(AppHealth::class);
        $health->method('report')->willReturnCallback(function (): ?array {
            return $this->reports[$this->reported++] ?? null;
        });
        $routedTo = &$this->routedTo;
        $networking = $this->createStub(Networking::class);
        $networking->method('routeTo')->willReturnCallback(function (User $user, int $port) use (&$routedTo): void {
            $routedTo[] = $port;
        });
        $environment = $this->createStub(ProjectEnvironment::class);
        if ($environmentFails) {
            $environment->method('forPortDetection')->willThrowException(new \RuntimeException('.env unreadable'));
        }

        $user = new User();
        $user->username = $this->username;
        $user->setDetails(['deploy_strategy' => 'dockerfile', ...$details]);

        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($inner);
        $project->method('hostCompile')->willReturn($hostCompile);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn($this->username);
        $project->method('userModel')->willReturn($user);
        $project->method('userAppComposeFileToRun')->willReturn('/home/acme/.panelalpha/docker-compose.yml');
        $project->method('userAppComposeFileForPorts')->willReturn($this->portsFile);
        $project->method('userAppComposeCommand')->willReturnCallback(
            static fn (array $rest): array => ['docker', 'compose', '--', ...$rest]
        );
        $project->method('registryLogin')->willReturn(new RegistryLogin($project));
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($project));
        $project->method('userAppComposeProjectName')->willReturn('project');
        $project->method('appHealth')->willReturn($health);
        $project->method('appCertificate')->willReturnCallback(static fn (): AppCertificate => new AppCertificate($project));
        $project->method('networking')->willReturn($networking);
        $project->method('environment')->willReturn($environment);

        return new AppLauncher($project);
    }
}
