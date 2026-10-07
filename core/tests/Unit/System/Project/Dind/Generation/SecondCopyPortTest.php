<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The second copy of a redeploy is started and probed on the port the app
 * binds, as a first start is (AppPortAlignment). A Dockerfile without EXPOSE
 * is written as 8080:80; cloud-run-hello binds 8080, so a copy left on 80 was
 * probed until the start period ran out and every warm rebuild failed.
 */
class SecondCopyPortTest extends TestCase
{
    private const RUN_PATH = '/home/u/project/docker-compose.panelalpha.yml';

    private const OCI_FAILED = 'Error response from daemon: failed to create task for container: failed to create shim task: '
        . 'OCI runtime create failed: runc create failed: unable to start container process: error during container init: ';

    private const ADDRESS_IN_USE = 'Error response from daemon: failed to set up container networking: Address already in use';

    private const HEADER =
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode';

    private string $username = '';

    private string $deployId = '';

    private string $runFile = '';

    private int $published = 0;

    private int $bound = 0;

    private int $copies = 0;

    /** @var list<string> */
    private array $ran = [];

    /** @var list<array<string, mixed>> the override of every start, parsed */
    private array $overrides = [];

    /** @var list<int> */
    private array $probed = [];

    /** What `docker inspect` says of a copy that went down; null for one that keeps running. */
    private ?string $copyDown = null;

    /** Socket polls the copy is still up for, before it goes down. */
    private int $upFor = 0;

    private int $polls = 0;

    /** Compose's error when starting the copy fails; null when it starts. */
    private ?string $upError = null;

    /** Whether that failed start left the copy's container behind. */
    private bool $created = false;

    /** Whether Docker names the published port of a copy that runs. */
    private bool $portNamed = true;

    /** The start $upError and $copyDown apply from: 1, or 2 for the one on the realigned run file. */
    private int $failingCopy = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'copy-' . bin2hex(random_bytes(6));
        config([
            'deploy.step_idle_timeout' => 0,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('proxy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('owner_scope')->default('user');
            $table->string('username')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('transport');
            $table->string('listen_ip')->nullable()->default('*');
            $table->unsignedInteger('listen_port');
            $table->string('server_name')->nullable();
            $table->string('upstream_host');
            $table->unsignedInteger('upstream_port');
            $table->string('upstream_protocol')->nullable();
            $table->boolean('is_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('domain')->index();
            $table->string('type');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        Schema::dropIfExists('domains');
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    /** @return iterable<string, array{0: int, 1: int, 2: int}> published, the run file's container port, the port the app binds */
    public static function bindsAnotherPort(): iterable
    {
        yield 'a Dockerfile without EXPOSE' => [8080, 80, 8080];
        yield 'EXPOSE 3000, PORT=8080 in its environment' => [3000, 3000, 8080];
    }

    #[DataProvider('bindsAnotherPort')]
    public function test_the_copy_follows_the_port_the_new_version_binds(int $published, int $guessed, int $bound): void
    {
        $rule = $this->deploying($published, $guessed, $bound);

        $begun = $this->plan()->begin($this->alignment());

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame([$guessed, $bound], array_map(static fn (array $o): int => $o['services']['app']['ports']->getValue()[0]['target'], $this->overrides));
        $this->assertCount(1, $this->ranMatching('--force-recreate app'), 'started again on the port it binds');
        $this->assertNotContains(32768, $this->probed, 'never probed where nothing listens');
        $this->assertContains(32769, $this->probed);
        $this->assertSame(32769, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([$published => 32769], (new GenerationState($this->username))->get(GenerationState::NEXT)['routes']);
        $this->assertSame([$published, $bound], $this->mapping(), 'the app itself is replaced on it too');
        $this->assertContains("Application is listening on port {$bound}, not {$guessed}; forwarding there instead", $this->logged());
    }

    public function test_a_copy_on_the_port_the_run_file_names_starts_once(): void
    {
        $rule = $this->deploying(8080, 8080, 8080);

        $begun = $this->plan()->begin($this->alignment());

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertCount(1, $this->overrides);
        $this->assertSame([], $this->ranMatching('--force-recreate'));
        $this->assertSame(32768, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([8080, 8080], $this->mapping());
    }

    /** @return iterable<string, array{0: int, 1: string, 2: string}> polls the copy survives, its state then, why it is refused */
    public static function stopsRunning(): iterable
    {
        yield 'in restart back-off once the alignment looked' => [1, 'restarting 1 2', 'it keeps restarting (last exit code 1)'];
        yield 'in restart back-off before its ports were read' => [0, 'restarting 1 1', 'it keeps restarting (last exit code 1)'];
        yield 'running again after restarts, on a new port' => [0, 'running 0 3', 'it keeps restarting (3 restarts so far)'];
        yield 'exited' => [0, 'exited 1 0', 'it exited with code 1'];
    }

    /** The new version exits at start: the running one keeps the site, as when a copy answers nothing. */
    #[DataProvider('stopsRunning')]
    public function test_a_copy_that_stops_running_is_refused_and_the_running_version_kept(int $upFor, string $down, string $why): void
    {
        $rule = $this->deploying(8080, 80, 0);
        $this->copyDown = $down;
        $this->upFor = $upFor;

        $begun = $this->plan()->begin($this->alignment());

        $this->assertIsArray($begun, 'refused, not replaced in place');
        $this->assertTrue($begun[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertSame("The new version did not become healthy: {$why}. The previous version is still serving.", $begun['stderr']);
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port, 'the site stays on the running version');
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        $this->assertNotEmpty($this->ranMatching('docker rm -f'), 'the copy is removed');
        $this->assertSame([], $this->ranMatching('--force-recreate'));
        $this->assertSame([], array_filter($this->logged(), static fn (string $line): bool => str_contains($line, 'in place')));
        $this->assertLessThanOrEqual($upFor + 1, $this->polls, 'the alignment stops waiting once the copy is down');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: string}> Docker's error, the exit code it left, the explainer's rule */
    public static function cannotRunItsCommand(): iterable
    {
        yield 'an entrypoint the image lacks' => ['exec: "server": executable file not found in $PATH: unknown', 127, 'container-entrypoint-missing'];
        yield 'an entrypoint that is not executable' => ['exec: "/srv/app": permission denied: unknown', 126, 'container-entrypoint-not-executable'];
    }

    /** Docker creates the container and cannot run the new version's command: refused, with Docker's reason. */
    #[DataProvider('cannotRunItsCommand')]
    public function test_a_copy_docker_cannot_run_is_refused(string $error, int $code, string $rule): void
    {
        $proxy = $this->deploying(8080, 80, 0);
        $this->upError = self::composeFailed(self::OCI_FAILED . $error);
        $this->created = true;
        $this->copyDown = "created {$code} 0";

        $begun = $this->plan()->begin($this->alignment());

        $this->assertIsArray($begun);
        $this->assertTrue($begun[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertStringStartsWith("The new version did not become healthy: it could not be started (exit code {$code}). The previous version is still serving.\n", $begun['stderr']);
        $this->assertStringContainsString($error, $begun['stderr']);
        $this->assertSame($rule, DeployFailureExplainer::match($begun['stderr'])['rule'] ?? null, 'the deploy names the cause, not just the code');
        $this->assertNotNull(DeployFailureExplainer::explain(FailureOutput::select($begun['stderr'])));
        $this->assertSame(8080, ProxyRule::find($proxy)->upstream_port);
        $this->assertSame(0, $this->polls);
    }

    /** Docker's 128: the start failed on something the running copy holds, which replacing it frees. */
    public function test_a_copy_docker_could_not_start_for_another_reason_is_replaced_in_place(): void
    {
        $proxy = $this->deploying(8080, 80, 0);
        $this->upError = self::composeFailed(self::ADDRESS_IN_USE);
        $this->created = true;
        $this->copyDown = 'created 128 0';

        $begun = $this->plan()->begin($this->alignment());

        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $begun);
        $this->assertContains('Replacing the running app in place after all: the second copy did not start: ' . self::ADDRESS_IN_USE, $this->logged());
        $this->assertSame(8080, ProxyRule::find($proxy)->upstream_port);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        $this->assertNotEmpty($this->ranMatching('docker rm -f'), 'the copy is removed');
    }

    /** @return iterable<string, array{0: ?string, 1: string, 2: string}> compose's error starting it again, its state then, why it is refused */
    public static function realignedThenStops(): iterable
    {
        yield 'Docker cannot run its command' => [self::OCI_FAILED . 'exec: "server": executable file not found in $PATH: unknown', 'created 127 0', 'it could not be started (exit code 127)'];
        yield 'it crashes before its ports are read' => [null, 'restarting 1 1', 'it keeps restarting (last exit code 1)'];
    }

    /** The copy is started again on the port the new version binds and stops there: refused all the same. */
    #[DataProvider('realignedThenStops')]
    public function test_a_copy_that_stops_once_started_on_the_bound_port_is_refused(?string $error, string $down, string $why): void
    {
        $proxy = $this->deploying(8080, 80, 8080);
        $this->failingCopy = 2;
        $this->upError = $error === null ? null : self::composeFailed($error);
        $this->created = true;
        $this->copyDown = $down;

        $begun = $this->plan()->begin($this->alignment());

        $this->assertContains('Application is listening on port 8080, not 80; forwarding there instead', $this->logged());
        $this->assertCount(1, $this->ranMatching('--force-recreate app'), 'started again on the port it binds');
        $this->assertIsArray($begun, 'refused, not replaced in place');
        $this->assertTrue($begun[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertStringStartsWith("The new version did not become healthy: {$why}. The previous version is still serving.", $begun['stderr']);
        $this->assertSame(8080, ProxyRule::find($proxy)->upstream_port, 'the site stays on the running version');
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        $this->assertNotEmpty($this->ranMatching('docker rm -f'), 'the copy is removed');
        $this->assertSame([], array_filter($this->logged(), static fn (string $line): bool => str_contains($line, 'in place')));
        $this->assertSame([], array_filter($this->probed, static fn (int $port): bool => $port >= 32768), 'the copy never reached the gate');
    }

    /** Nothing of the new version ran, so nothing says it is broken: replace in place, as before. */
    public function test_a_copy_compose_did_not_create_is_replaced_in_place(): void
    {
        $this->deploying(8080, 80, 0);
        $this->upError = 'network project_default declared as external, but could not be found';

        $begun = $this->plan()->begin($this->alignment());

        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $begun);
        $this->assertContains('Replacing the running app in place after all: the second copy did not start: network project_default declared as external, but could not be found', $this->logged());
    }

    public function test_a_running_copy_whose_port_docker_does_not_name_is_replaced_in_place(): void
    {
        $this->deploying(8080, 8080, 8080);
        $this->portNamed = false;

        $begun = $this->plan()->begin($this->alignment());

        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $begun);
        $this->assertContains('Replacing the running app in place after all: the second copy published no port Docker would name', $this->logged());
    }

    private function deploying(int $published, int $guessed, int $bound): int
    {
        $this->runFile = "services:\n  app:\n    build: .\n    ports:\n      - \"{$published}:{$guessed}\"\n";
        $this->published = $published;
        $this->bound = $bound;
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $this->deployId = $logger->getDeployId();

        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true, 'transport' => 'http',
            'listen_port' => 443, 'upstream_host' => $this->username, 'upstream_port' => $published, 'is_generated' => true,
        ])->id;
    }

    private function plan(): ZeroDowntimeRedeploy
    {
        $swap = ZeroDowntimeRedeploy::plan($this->project());
        $this->assertInstanceOf(ZeroDowntimeRedeploy::class, $swap);

        return $swap;
    }

    private function alignment(): AppPortAlignment
    {
        return new AppPortAlignment($this->project(), static function (int $seconds): void {
        });
    }

    private function project(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileGetContents')->willReturnCallback(fn (string $path): string => $path === self::RUN_PATH ? $this->runFile : '');
        $filesystem->method('filePutContents')->willReturnCallback(function (string $path, string $contents): void {
            if ($path === self::RUN_PATH) {
                $this->runFile = $contents;
            }
        });
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturn('nginx-proxy');
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('webserver')->willReturn($webserver);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account(is_array($cmd) ? $cmd : [$cmd]));
        $system->method('runProcessWithCallbacks')->willReturnCallback(function (array $cmd): Process {
            $this->account($cmd);
            $fails = $this->upError !== null && str_contains(implode(' ', $cmd), 'project-next up -d --no-deps') && $this->copies >= $this->failingCopy;
            $process = $fails
                ? new Process(['php', '-r', 'fwrite(STDERR, getenv("UP_ERROR")); exit(1);'], null, ['UP_ERROR' => $this->upError])
                : new Process(['php', '-r', 'exit(0);']);
            $process->run();

            return $process;
        });

        $user = new User();
        $user->username = $this->username;
        $user->details = [
            'app_port' => $this->published,
            'deploy_strategy' => 'dockerfile',
            AppHealth::DETAIL_START_PERIOD => 30,
        ];

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $project->method('userAppComposeFileToRun')->willReturn(self::RUN_PATH);
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = implode(' ', $argv);
        $this->ran[] = $line;
        if (str_contains($line, 'base64 -d')) {
            $this->overrides[] = Yaml::parse((string) base64_decode($argv[array_search('override', $argv, true) + 1]), Yaml::PARSE_CUSTOM_TAGS);

            return '';
        }
        if (str_contains($line, "'config' '--format' 'json'")) {
            return (string) json_encode($this->config());
        }
        if (str_contains($line, "'ps' '--quiet' '--status' 'running'")) {
            return "old1\n";
        }
        if (str_contains($line, 'project-next up -d --no-deps')) {
            $this->copies++;

            return '';
        }
        if (str_contains($line, 'docker ps -aq') && str_contains($line, 'project-next')) {
            return $this->upError !== null && !$this->created && $this->copies >= $this->failingCopy ? '' : "next{$this->copies}\n";
        }
        if (preg_match('/docker port (next\d+) (\d+)\/tcp/', $line, $m) === 1) {
            if (!$this->portNamed) {
                return '';
            }
            if ($this->copyIsDown()) {
                // Nothing for a copy in restart back-off; one running again got a new port.
                return str_starts_with((string) $this->copyDown, 'running') ? "0.0.0.0:32770\n" : '';
            }

            // Docker publishes whatever the copy was started with; only the second start is new.
            return '0.0.0.0:' . ($m[1] === 'next1' ? 32768 : 32769) . "\n";
        }
        if (str_contains($line, 'net/tcp')) {
            $down = $this->copyIsDown();
            $this->polls++;
            // The script prints nothing for a container that does not run; a crashing app binds nothing.
            if ($down || $this->bound === 0) {
                return $down ? '' : self::HEADER . "\n";
            }

            return self::HEADER . "\n" . sprintf('   0: 00000000:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1', $this->bound) . "\n";
        }
        if (str_contains($line, 'NetworkSettings.Networks')) {
            return preg_match('/ip:(\d+)\//', $line, $m) === 1 && (int) $m[1] === $this->bound ? '200' : '000';
        }
        if (str_contains($line, '{{.State.Status}} {{.State.ExitCode}} {{.RestartCount}}')) {
            return preg_match('/ next\d+$/', $line) === 1 && $this->copyIsDown() ? (string) $this->copyDown : 'running 0 0';
        }
        if (preg_match('/for port in ([\d ]+); do/', $line, $m) === 1) {
            $out = '';
            foreach (array_map('intval', explode(' ', trim($m[1]))) as $port) {
                $this->probed[] = $port;
                // Only the copy started on the bound port reaches the app; the old one answers on its own.
                $answers = $port === 32769 || ($port === 32768 && $this->bound === $this->mapping()[1]) || $port < 32768;
                // A page, not just a 200: the gate turns an empty one away.
                $out .= $answers
                    ? "{$port}\thttp\t200 0.010\t\t" . base64_encode('<html>ok</html>') . "\t/\n"
                    : "{$port}\thttp\t000 0.001\tRecv failure: Connection reset by peer\n";
            }

            return $out;
        }

        return '';
    }

    private function copyIsDown(): bool
    {
        return $this->copyDown !== null && $this->copies >= $this->failingCopy && $this->polls >= $this->upFor;
    }

    /** Compose's stderr for a start that failed: its progress, then Docker's error. */
    private static function composeFailed(string $error): string
    {
        return " Container project-next-app-1  Creating\n Container project-next-app-1  Created\n Container project-next-app-1  Starting\n{$error}\n";
    }

    /** @return array{0: int, 1: int} the run file's published and container port, as it stands */
    private function mapping(): array
    {
        $entry = (string) (Yaml::parse($this->runFile)['services']['app']['ports'][0] ?? '');
        [$published, $container] = array_map('intval', array_pad(explode(':', $entry, 2), 2, 0));

        return [$published, $container];
    }

    /**
     * `docker compose config --format json` of the run file as it stands.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        [$published, $target] = $this->mapping();

        return [
            'name' => 'project',
            'networks' => ['default' => ['name' => 'project_default']],
            'services' => [
                'app' => [
                    'build' => ['context' => '/home/u/project', 'dockerfile' => 'Dockerfile'],
                    'networks' => ['default' => null],
                    'ports' => [['mode' => 'ingress', 'target' => $target, 'published' => (string) $published, 'protocol' => 'tcp']],
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function ranMatching(string $needle): array
    {
        return array_values(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)));
    }

    /** @return list<string> */
    private function logged(): array
    {
        return array_map(static fn (array $entry): string => (string) $entry['msg'], DeployLogger::forDeploy($this->username, $this->deployId)->tail(200));
    }
}
