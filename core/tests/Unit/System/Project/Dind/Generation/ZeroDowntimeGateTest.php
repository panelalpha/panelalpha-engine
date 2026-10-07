<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
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
 * The zero-downtime gate deciding on an empty page, run through
 * plan() and begin() against a scripted account, on a fake clock.
 */
class ZeroDowntimeGateTest extends TestCase
{
    private const PAGE = '<html><title>v1</title></html>';

    /** Where the second copy's app port is published. */
    private const NEXT_PORT = 32768;

    private string $username = '';

    private ?DeployLogger $logger = null;

    /** @var array<int, list<string>> port => the bodies it answers 200 with, one per probe, the last repeated */
    private array $bodies = [];

    /** @var array<int, int> port => probes made */
    private array $probes = [];

    /** @var list<string> what the account was asked to run */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'gate-' . bin2hex(random_bytes(6));
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
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    /** An empty page for the whole minute a 5xx gets: refused, and the running version left serving. */
    public function test_a_new_version_answering_an_empty_page_for_a_minute_is_refused(): void
    {
        $this->bodies = [3000 => [self::PAGE], self::NEXT_PORT => ['']];

        $swap = ZeroDowntimeRedeploy::plan($this->project());
        $this->assertInstanceOf(ZeroDowntimeRedeploy::class, $swap);
        $result = $swap->begin();

        $this->assertIsArray($result);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame(ZeroDowntimeRedeploy::refusal('it answers HTTP 200 with an empty page'), $result['stderr']);
        // Asked every 2 s from the first empty answer until 60 s had passed.
        $this->assertSame(31, $this->probes[self::NEXT_PORT]);
        Sleep::assertSleptTimes(30);
        $lines = $this->lines();
        $this->assertContains('[warn] The new version did not become healthy on port 32768: it answers HTTP 200 with an empty page', $lines);
        $this->assertNotContains('[ok] The new version answers on port 32768', $lines);
        $this->assertRan('docker rm -f -v');
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT), 'the second copy is gone');
    }

    /** A new version may answer empty while it boots: within the minute, a page lets it through. */
    public function test_an_empty_page_that_becomes_a_page_within_the_minute_passes_the_gate(): void
    {
        $this->bodies = [3000 => [self::PAGE], self::NEXT_PORT => [...array_fill(0, 20, ''), '<html>v2</html>']];

        $result = ZeroDowntimeRedeploy::plan($this->project())->begin();

        $this->assertContains('[ok] The new version answers on port 32768', $this->lines());
        $this->assertSame(21, $this->probes[self::NEXT_PORT]);
        Sleep::assertSleptTimes(20);
        // This account has no proxy rule to move, so the switch itself falls back to in place.
        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $result);
    }

    /** A running version serving an empty page has nothing to keep: the redeploy goes in place and says why. */
    public function test_a_running_version_serving_an_empty_page_is_replaced_in_place(): void
    {
        $this->bodies = [3000 => ['']];

        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project()));

        $this->assertContains(
            '[info] Replacing the running app in place, not beside it: the running version does not answer on port 3000 '
                . '(HTTP 200 with an empty page), so there is nothing to keep serving',
            $this->lines()
        );
        $this->assertArrayNotHasKey(self::NEXT_PORT, $this->probes);
        $this->assertNotRan('project-next');
    }

    private function project(): Dind
    {
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturn(RouteSwitch::WEBSERVER);
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
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = implode(' ', $argv);
        $this->ran[] = $line;
        if (preg_match('/for port in (\d+); do/', $line, $m) === 1) {
            $port = (int) $m[1];
            $seen = $this->probes[$port] = ($this->probes[$port] ?? 0) + 1;
            $bodies = $this->bodies[$port] ?? [];
            $body = $bodies[min($seen, count($bodies)) - 1] ?? '';

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
            str_contains($line, 'docker logs') => "booting v2\nlistening 3000\n",
            default => '',
        };
    }

    /** @return list<string> the deploy log, as "[level] message" */
    private function lines(): array
    {
        return array_map(
            static fn (array $line): string => "[{$line['level']}] {$line['msg']}",
            DeployLogger::forDeploy($this->username, $this->logger->getDeployId())->tail(500)
        );
    }

    private function assertRan(string $needle): void
    {
        $this->assertNotEmpty(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)), "ran: {$needle}");
    }

    private function assertNotRan(string $needle): void
    {
        $this->assertEmpty(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)), "did not run: {$needle}");
    }
}
