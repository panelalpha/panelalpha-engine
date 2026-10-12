<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\RouteSwitch;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * When the zero-downtime route switch throws and the undo throws too, some
 * rules may still point at the copy's port. The copy and NEXT, with the rule
 * ids actually left there, must be kept for the sweep -- discarding both would
 * leave the site answering 502 on a port nothing listens on.
 */
class ZeroDowntimeFailedSwitchTest extends TestCase
{
    private const PAGE = '<html><title>v1</title></html>';

    private const NEXT_PORT = 32768;

    private string $username = '';

    private ?DeployLogger $logger = null;

    /** @var list<string> */
    private array $ran = [];

    /** Every reload throws, so the switch and its undo both fail. */
    private bool $reloadFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'switch-' . bin2hex(random_bytes(6));
        config([
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
        ProxyRule::create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true,
            'transport' => 'http', 'listen_ip' => '*', 'listen_port' => 80, 'server_name' => $this->username . '.test',
            'upstream_host' => $this->username, 'upstream_port' => 3000, 'upstream_protocol' => 'http', 'is_generated' => false,
        ]);
        $this->logger = DeployLogger::start($this->username);
        $this->logger->stage(DeployLogger::STAGE_RUNNING);
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 12));
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        $this->logger = null;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        Schema::dropIfExists('domains');
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    public function test_a_failed_switch_whose_undo_fails_keeps_the_copy_and_the_next_state(): void
    {
        $this->reloadFails = true;

        $swap = ZeroDowntimeRedeploy::plan($this->project());
        $this->assertInstanceOf(ZeroDowntimeRedeploy::class, $swap);
        $result = $swap->begin();

        $this->assertIsArray($result);
        $this->assertTrue($result[ZeroDowntimeRedeploy::COPY_KEPT]);
        $this->assertSame(1, $result['exit_code']);
        $this->assertNotRan('docker rm -f -v');

        $next = (new GenerationState($this->username))->get(GenerationState::NEXT);
        $this->assertNotNull($next, 'NEXT is kept for the sweep to repair from');
        $this->assertSame($this->servedCopyRoute(), $next['routes']);
        $this->assertNotEmpty($next['rules'], 'the rule ids left on the copy port are recorded');
    }

    /** @return array<int, int> */
    private function servedCopyRoute(): array
    {
        return [3000 => self::NEXT_PORT];
    }

    private function project(): Dind
    {
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturn(RouteSwitch::WEBSERVER);
        $webserver->method('reload')->willReturnCallback(function (): void {
            if ($this->reloadFails) {
                throw new \RuntimeException('nginx: [emerg] could not be reloaded');
            }
        });
        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($webserver);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account(is_array($cmd) ? $cmd : [$cmd]));
        $system->method('runProcessWithCallbacks')->willReturn($process);

        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturn(3000);
        $user->method('getDetails')->willReturn([]);
        $user->method('getDeployStrategy')->willReturn('dockerfile');
        $user->method('getMainDomain')->willReturn(null);
        $user->method('getChownString')->willReturn('1000:1000');

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($project));

        return $project;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = implode(' ', $argv);
        $this->ran[] = $line;
        if (preg_match('/for port in (\d+); do/', $line, $m) === 1) {
            $port = (int) $m[1];
            $body = $port === 3000 ? self::PAGE : self::PAGE;

            return "{$port}\thttp\t200 0.002\t\t" . base64_encode($body) . "\t/\n";
        }
        if (str_contains($line, "'config' '--format' 'json'")) {
            return (string) json_encode([
                'name' => 'project',
                'services' => ['app' => [
                    'build' => ['context' => '/home/u/project'],
                    'ports' => [['mode' => 'ingress', 'target' => 3000, 'published' => '3000', 'protocol' => 'tcp']],
                ]],
            ]);
        }

        return match (true) {
            str_contains($line, "'ps' '--quiet' '--status' 'running'") => "old1\n",
            str_contains($line, 'docker rm -f -v') => '',
            str_contains($line, 'label=com.docker.compose.service=app') => "next1\n",
            str_contains($line, 'docker port next1 3000/tcp') => '0.0.0.0:' . self::NEXT_PORT . "\n",
            str_contains($line, 'docker inspect') => "running 0 0\n",
            str_contains($line, 'docker logs') => "booting v2\n",
            default => '',
        };
    }

    private function assertRan(string $needle): void
    {
        $this->assertNotEmpty(array_filter($this->ran, static fn (string $l): bool => str_contains($l, $needle)), "ran: {$needle}");
    }

    private function assertNotRan(string $needle): void
    {
        $this->assertEmpty(array_filter($this->ran, static fn (string $l): bool => str_contains($l, $needle)), "did not run: {$needle}");
    }
}
