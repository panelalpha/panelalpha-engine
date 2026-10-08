<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AnsweringPort;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A compose app publishing a stream on 5004 and its web UI on 6077 (Cabernet).
 * The first deploy guessed 5004 and moved the site to 6077 once 6077 served a
 * page. A rebuild probes and switches the second copy on the port that serves:
 * the kept 6077 while the same services stand behind the guessed ports, the
 * guess made again on the copy otherwise.
 */
class CopyAnsweringPortTest extends TestCase
{
    private const PROVEN = 'app_port_proven';

    private const CABERNET = ['primary' => 5004, 'alternatives' => [6077]];

    /** The copy's port Docker publishes for each container port: the stream binds 5004, the UI 6077. */
    private const COPY_PORTS = [5004 => 32768, 6077 => 32769];

    private string $username = '';

    private string $deployId = '';

    private string $compose = '';

    /** @var array<int, int> published => container port, in the run file the rebuild prepared */
    private array $targets = [5004 => 5004, 6077 => 6077];

    /** @var array<int, string> the copy's port => what it answers: page, silent, or an HTTP code */
    private array $copyAnswers = [];

    /** What `docker inspect` says of the copy once it has been probed $upFor times. */
    private string $copyDown = 'running 0 0';

    private int $upFor = PHP_INT_MAX;

    /** @var list<int> */
    private array $probed = [];

    /** @var list<int> */
    private array $routedTo = [];

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'answer-' . bin2hex(random_bytes(6));
        $this->compose = sys_get_temp_dir() . '/' . $this->username . '.yml';
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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->nullable();
            $table->text('details')->nullable();
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
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 9));
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        $this->user = null;
        gc_collect_cycles();
        @unlink($this->compose);
        DeployLogger::deleteUserLogs($this->username);
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    /** The same services behind the same ports: the copy is probed and switched on the kept 6077, never on 5004. */
    public function test_with_nothing_changed_the_copy_is_probed_and_switched_on_the_kept_port(): void
    {
        $this->deployedOn(6077);
        $rule = $this->rebuilding(served: 6077);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame(6077, $this->user->getAppPort(), 'the rebuild routes to the kept port');
        $this->assertContains("Keeping the site on port 6077, where the last deploy found it served, not on the guessed 5004: nothing behind those ports has changed", $this->logged());
        $this->assertNotContains(32768, $this->probed, 'the stream is never asked');
        $this->assertSame(32769, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([6077 => 32769], (new GenerationState($this->username))->get(GenerationState::NEXT)['routes']);
        $this->assertSame([], $this->loggedMatching('Asking the new version'));
        $this->assertNull($this->user->getDetails()[self::PROVEN], 'used up: only a rebuild that serves again writes it back');
    }

    /** The lowest port served last time: kept as well, and the other one is not asked. */
    public function test_with_nothing_changed_a_kept_lowest_port_is_not_chosen_again(): void
    {
        $this->copyAnswers[32768] = 'page';
        $this->deployedOn(5004);
        $rule = $this->rebuilding(served: 5004);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame([], $this->routedTo);
        $this->assertNotContains(32769, $this->probed);
        $this->assertSame(32768, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->loggedMatching('Asking the new version'));
    }

    /** @return iterable<string, array{0: string}> what the stream port answers */
    public static function streamAnswers(): iterable
    {
        yield 'a silent stream' => ['silent'];
        yield 'an API answering 404' => ['404'];
    }

    /**
     * The same published ports, the container ports swapped behind them: the UI is behind 5004 now. Not kept:
     * the guess is made again on the copy, which serves on 5004.
     */
    #[DataProvider('streamAnswers')]
    public function test_container_ports_swapped_behind_the_same_ports_are_chosen_again(string $stream): void
    {
        $this->deployedOn(6077);
        $this->targets = [5004 => 6077, 6077 => 5004];
        $this->copyAnswers[32768] = $stream;
        $rule = $this->rebuilding(served: 6077);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame([], $this->loggedMatching('Keeping the site'));
        $this->assertContains('Asking the new version which of ports 5004, 6077 serves the site: no deploy found it with what serves behind them now', $this->logged());
        $this->assertSame(32769, ProxyRule::find($rule)->upstream_port, 'switched on the copy of the UI');
        $this->assertSame(5004, (new GenerationState($this->username))->get(GenerationState::NEXT)['routed']);
        $this->assertSame(5004, $this->user->getAppPort(), 'the guess serves the site now');
    }

    /** @return iterable<string, array{0: mixed}> what the last deploy left */
    public static function notSettledForTheseServices(): iterable
    {
        yield 'another equal port then' => [[5004 => 5004, 6078 => 6078]];
        yield 'nothing recorded' => [null];
    }

    /** The guess is 5004 again: the copy is asked first, and the site goes to 6077 before the copy is probed. */
    /** @param ?array<int, int> $then the ports the first deploy published, or none deployed with a record */
    #[DataProvider('notSettledForTheseServices')]
    public function test_otherwise_the_copy_is_asked_first_and_switched_on_the_port_that_serves(?array $then): void
    {
        if ($then !== null) {
            $now = $this->targets;
            $this->targets = $then;
            $this->deployedOn(6078);
            $this->targets = $now;
        }
        $rule = $this->rebuilding(served: 6077);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame([6077], $this->routedTo, 'the site follows the copy');
        $this->assertContains('Routing the site to port 6077 instead of 5004: on the new version 5004 did not answer, 6077 answered 200', $this->logged());
        $this->assertSame(32769, ProxyRule::find($rule)->upstream_port, 'switched on the copy of 6077');
        $state = (new GenerationState($this->username))->get(GenerationState::NEXT);
        $this->assertSame(6077, $state['routed']);
        $this->assertSame([6077 => 32769], $state['routes']);
        $this->assertSame([], $this->loggedMatching('did not become healthy'));
        $this->assertGreaterThan(1, count(array_keys($this->probed, 32768)), 'a silent port gets its window');
    }

    /** Cabernet's stream answers 501: every port has answered, so the guess is made on the first probe. */
    public function test_a_guess_answering_without_a_page_is_left_at_once(): void
    {
        $this->copyAnswers[32768] = '501';
        $rule = $this->rebuilding(served: 6077);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertContains('Routing the site to port 6077 instead of 5004: on the new version 5004 answered 501, 6077 answered 200', $this->logged());
        $this->assertSame([32768], array_values(array_filter($this->probed, static fn (int $p): bool => $p === 32768)));
        $this->assertSame(32769, ProxyRule::find($rule)->upstream_port);
    }

    /** Neither port serves a page but both answered: decided at once, the guess stays, nothing waited. */
    public function test_ports_that_all_answered_without_a_page_are_decided_at_once(): void
    {
        $this->copyAnswers = [32768 => '404', 32769 => '404'];
        $rule = $this->rebuilding(served: 6077);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame([], $this->routedTo);
        $this->assertSame(32768, ProxyRule::find($rule)->upstream_port);
        Sleep::assertNeverSlept();
    }

    /** A copy serving on the guess stays there, as after a first deploy. */
    public function test_a_copy_serving_on_the_guess_stays_on_it(): void
    {
        $this->copyAnswers[32768] = 'page';
        $rule = $this->rebuilding(served: 6077);

        $begun = $this->plan()->begin();

        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $begun);
        $this->assertSame([], $this->routedTo);
        $this->assertSame(32768, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->loggedMatching('Routing the site'));
    }

    /** Nothing of the copy answers: refused once the start period is out, not waited for twice. */
    public function test_a_copy_answering_on_no_port_is_refused_after_one_start_period(): void
    {
        $this->copyAnswers[32769] = 'silent';
        $rule = $this->rebuilding(served: 6077);
        $started = Carbon::now()->getTimestamp();

        $begun = $this->plan()->begin();

        $this->assertIsArray($begun);
        $this->assertTrue($begun[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertStringStartsWith('The new version did not become healthy: nothing answered within 30 s', $begun['stderr']);
        $this->assertSame(6077, ProxyRule::find($rule)->upstream_port, 'the site stays on the running version');
        $this->assertLessThan(60, Carbon::now()->getTimestamp() - $started);
    }

    /** The copy starts restarting while it is asked: refused at once, never moved, never replaced in place. */
    public function test_a_copy_crash_looping_while_it_is_asked_is_refused(): void
    {
        $this->copyDown = 'restarting 1 1';
        $this->upFor = 2;
        $rule = $this->rebuilding(served: 6077);
        $started = Carbon::now()->getTimestamp();

        $begun = $this->plan()->begin();

        $this->assertIsArray($begun);
        $this->assertTrue($begun[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertSame('The new version did not become healthy: it keeps restarting (last exit code 1). The previous version is still serving.', $begun['stderr']);
        $this->assertSame(6077, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->routedTo);
        $this->assertSame([], $this->loggedMatching('in place'));
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        $this->assertLessThan(10, Carbon::now()->getTimestamp() - $started, 'not the whole window');
    }

    /** The first deploy, its health check settled on $port: $port served a page, the other port nothing. */
    private function deployedOn(int $port): void
    {
        $this->user = $this->newUser($port);
        $this->writeCompose();
        $ports = array_keys($this->targets);
        AnsweringPort::remember($this->project(), ['ports' => array_map(
            static fn (int $p): array => ['port' => $p, 'status' => $p === $port ? 'ok' : 'fail', 'http_code' => $p === $port ? 200 : null],
            $ports
        )]);
        $this->assertNotNull($this->user->getDetails()[self::PROVEN] ?? null, 'the first deploy recorded where it settled');
    }

    /** A rebuild up to plan(): its run file as $targets says, detection as it runs, the site still on $served. */
    private function rebuilding(int $served): int
    {
        $this->copyAnswers += [32768 => 'silent', 32769 => 'page'];
        $this->user ??= $this->newUser($served);
        $this->writeCompose();
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $this->deployId = $logger->getDeployId();
        (new GenerationState($this->username))->put(GenerationState::ROUTES, [
            'details' => ['app_port' => $served], 'containers' => ['old1'], 'applied' => false, 'gated' => false,
            'deploy' => $this->deployId, 'owner' => GenerationState::owner(),
        ]);
        (new class ($this->project()) extends Networking {
            public function routeTo(User $user, int $primaryPort): void
            {
                $user->setAppPort($primaryPort);
            }
        })->detectAndCreateProxyRules($this->user);

        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true, 'transport' => 'http',
            'listen_port' => 443, 'upstream_host' => $this->username, 'upstream_port' => $served, 'is_generated' => true,
        ])->id;
    }

    private function newUser(int $port): User
    {
        $user = new User();
        $user->username = $this->username;
        $user->details = ['app_port' => $port, 'deploy_strategy' => 'compose', AppHealth::DETAIL_START_PERIOD => 30];
        $user->save();

        return $user;
    }

    private function writeCompose(): void
    {
        $ports = '';
        foreach ($this->targets as $published => $target) {
            $ports .= "      - \"{$published}:{$target}\"\n";
        }
        file_put_contents($this->compose, "services:\n  web:\n    build: .\n    ports:\n{$ports}");
    }

    private function plan(): ZeroDowntimeRedeploy
    {
        $swap = ZeroDowntimeRedeploy::plan($this->project());
        $this->assertInstanceOf(ZeroDowntimeRedeploy::class, $swap);

        return $swap;
    }

    private function project(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturn('nginx-proxy');
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('webserver')->willReturn($webserver);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account(is_array($cmd) ? $cmd : [$cmd]));
        $system->method('runProcessWithCallbacks')->willReturnCallback(function (array $cmd): Process {
            $this->account($cmd);
            $process = new Process(['php', '-r', 'exit(0);']);
            $process->run();

            return $process;
        });
        $environment = $this->createStub(ProjectEnvironment::class);
        $environment->method('forPortDetection')->willReturn([]);
        $networking = $this->createStub(Networking::class);
        $networking->method('routeTo')->willReturnCallback(function (User $user, int $port): void {
            $user->setAppPort($port);
            $this->routedTo[] = $port;
        });

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($this->user);
        $project->method('environment')->willReturn($environment);
        $project->method('networking')->willReturn($networking);
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeFileForPorts')->willReturn($this->compose);
        $project->method('userAppComposeFileToRun')->willReturn('/home/u/project/docker-compose.panelalpha.yml');
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }

    /** @return array<string, mixed> `docker compose config --format json` of the run file as it stands */
    private function config(): array
    {
        $ports = [];
        foreach ($this->targets as $published => $target) {
            $ports[] = ['mode' => 'ingress', 'target' => $target, 'published' => (string) $published, 'protocol' => 'tcp'];
        }

        return [
            'name' => 'project',
            'networks' => ['default' => ['name' => 'project_default']],
            'services' => ['web' => [
                'build' => ['context' => '/home/u/project', 'dockerfile' => 'Dockerfile'],
                'networks' => ['default' => null],
                'ports' => $ports,
            ]],
        ];
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = implode(' ', $argv);
        if (str_contains($line, "'config' '--format' 'json'")) {
            return (string) json_encode($this->config());
        }
        if (str_contains($line, "'ps' '--quiet' '--status' 'running'")) {
            return "old1\n";
        }
        if (str_contains($line, 'docker ps -aq') && str_contains($line, 'project-next')) {
            return "next1\n";
        }
        if (preg_match('/docker port next1 (\d+)\/tcp/', $line, $m) === 1) {
            return '0.0.0.0:' . self::COPY_PORTS[(int) $m[1]] . "\n";
        }
        if (str_contains($line, '{{.State.Status}} {{.State.ExitCode}} {{.RestartCount}}')) {
            $copyProbes = count(array_filter($this->probed, static fn (int $p): bool => $p >= 32768));

            return str_ends_with(trim($line), 'next1') && $copyProbes >= $this->upFor ? $this->copyDown : 'running 0 0';
        }
        if (preg_match('/for port in ([\d ]+); do/', $line, $m) === 1) {
            $out = '';
            foreach (array_map('intval', explode(' ', trim($m[1]))) as $port) {
                $this->probed[] = $port;
                // The running version answers on its own ports.
                $answer = $port < 32768 ? 'page' : $this->copyAnswers[$port];
                $out .= match ($answer) {
                    'page' => "{$port}\thttp\t200 0.010\t\t" . base64_encode('<html><title>UI</title></html>') . "\t/\n",
                    'silent' => "{$port}\thttp\t000 0.001\tReceived HTTP/0.9 when not allowed\n",
                    default => "{$port}\thttp\t{$answer} 0.002\t\t" . base64_encode('Not Found') . "\t/\n",
                };
            }

            return $out;
        }

        return '';
    }

    /** @return list<string> */
    private function logged(): array
    {
        return array_map(static fn (array $entry): string => (string) $entry['msg'], DeployLogger::forDeploy($this->username, $this->deployId)->tail(400));
    }

    /** @return list<string> */
    private function loggedMatching(string $needle): array
    {
        return array_values(array_filter($this->logged(), static fn (string $line): bool => str_contains($line, $needle)));
    }
}
