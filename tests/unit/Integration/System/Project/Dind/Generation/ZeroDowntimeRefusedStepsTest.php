<?php

namespace Tests\Integration\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A zero-downtime redeploy whose host steps fail one at a time: the webserver, the account's daemon, the
 * second copy's override, the previous version's restart. What serves afterwards, and what is left for the sweep.
 */
class ZeroDowntimeRefusedStepsTest extends TestCase
{
    private const RUN_PATH = '/home/u/project/docker-compose.panelalpha.yml';

    private const COPY = 32768;

    private const OLD_IMAGE = 'sha256:bbbbbbbbbbbb0000000000000000000000000000000000000000000000000000';

    private const HEADER =
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode';

    private string $username = '';

    private string $deployId = '';

    private ?User $user = null;

    private ?ZeroDowntimeRedeploy $swap = null;

    /** @var list<string> */
    private array $ran = [];

    /** @var list<int> */
    private array $routed = [];

    private bool $appAnswers = true;

    private bool $previousAnswers = true;

    private bool $previousUp = false;

    private bool $copyAnswers = true;

    private bool $held = true;

    /** @var array<string, string> a line containing the key fails with the value */
    private array $failing = [];

    /** How many webserver reloads fail from now on. */
    private int $reloadFailures = 0;

    private bool $overrideFails = false;

    private string $webserverName = 'nginx-proxy';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'refused-' . bin2hex(random_bytes(6));
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
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 18));
        Sleep::fake(syncWithCarbon: true);
        GenerationSweep::$backAnswerSeconds = 0;
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        GenerationSweep::$backAnswerSeconds = 10;
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        Schema::dropIfExists('domains');
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    public function test_a_webserver_that_cannot_move_traffic_back_leaves_the_copy_serving(): void
    {
        $rule = $this->switched();
        $this->reloadFailures = 1;

        $this->assertNull($this->swap->finish(true));

        $this->assertSame([], $this->ranMatching('account-docker.removeContainers'), 'the copy is not removed');
        $this->assertNotNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        $this->assertContains('Traffic could not be moved back yet, so the second copy keeps serving: nginx reload failed', $this->logged());
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port, 'the row moved before the reload failed');
    }

    public function test_an_abandoned_switch_the_webserver_cannot_undo_keeps_the_copy(): void
    {
        $this->switched();
        $this->reloadFailures = 1;

        $this->swap->abandon();

        $this->assertSame([], $this->ranMatching('account-docker.removeContainers'));
        $this->assertContains('Traffic could not be moved back yet, so the second copy keeps serving: nginx reload failed', $this->logged());
    }

    public function test_a_copy_whose_override_cannot_be_removed_stays_recorded_for_the_sweep(): void
    {
        Log::spy();
        $rule = $this->switched();
        $this->failing = ['files.removeIfExists' => 'removal refused'];

        $this->assertNull($this->swap->finish(true));

        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertNotNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_starts_with($m, "Could not remove the second generation of {$this->username}"));
    }

    public function test_a_silent_replaced_app_whose_output_cannot_be_read_is_still_rolled_back(): void
    {
        $this->switched();
        $this->appAnswers = false;
        $this->failing = ['account-docker.logTail' => 'log refused'];

        $result = $this->swap->finish(true);

        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertNotContains('The new version printed:', $this->logged());
    }

    public function test_a_replaced_app_the_daemon_cannot_inspect_reads_as_gone(): void
    {
        $this->switched();
        $this->appAnswers = false;
        $this->failing = ['account-docker.containers -- ' => 'inspect refused'];

        $result = $this->swap->finish(true);

        $this->assertStringContainsString('did not answer on its own port 8080 once it replaced the running one: its container is gone', $result['stderr']);
    }

    /** @return iterable<string, array{0: string, 1: string}> the line that fails, why the previous version could not start */
    public static function previousCannotStart(): iterable
    {
        yield 'its checkout cannot be put back' => ['files.rename', 'its checkout could not be put back'];
        yield 'its images cannot be named again' => ['account-docker.tag', 'its images could not be named again'];
        yield 'compose cannot start it' => ["'up' '-d' '--remove-orphans' '--no-build'", 'it did not start: compose refused'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('previousCannotStart')]
    public function test_when_the_previous_version_cannot_be_started_the_copy_keeps_the_site(string $line, string $why): void
    {
        $rule = $this->switched();
        $this->appAnswers = false;
        $this->failing = [$line => 'compose refused'];

        $result = $this->swap->finish(true);

        $this->assertTrue($result[ZeroDowntimeRedeploy::COPY_KEPT]);
        $this->assertStringContainsString("The previous version could not be started again ({$why}", $result['stderr']);
        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);
    }

    public function test_a_previous_version_the_site_cannot_be_moved_to_leaves_the_copy_serving(): void
    {
        $this->switched();
        $this->appAnswers = false;
        $this->reloadFailures = 1;

        $result = $this->swap->finish(true);

        $this->assertTrue($result[ZeroDowntimeRedeploy::COPY_KEPT]);
        $this->assertStringContainsString('The previous version could not be started again (the site could not be moved to it: nginx reload failed)', $result['stderr']);
    }

    public function test_a_switch_the_webserver_refuses_once_is_undone_and_replaced_in_place(): void
    {
        $rule = $this->planned();
        $this->reloadFailures = 1;

        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $this->swap->begin(new AppPortAlignment($this->project(), static function (int $seconds): void {
        })));

        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertContains('Replacing the running app in place after all: the webserver could not be switched: nginx reload failed', $this->logged());
    }

    public function test_an_override_that_cannot_be_written_replaces_in_place(): void
    {
        $this->planned();
        $this->overrideFails = true;

        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $this->swap->begin());

        $this->assertContains('Replacing the running app in place after all: its override could not be written: disk full', $this->logged());
        $this->assertSame([], $this->ranMatching('--project-name'), 'no copy was started');
    }

    public function test_a_copy_the_daemon_cannot_list_replaces_in_place(): void
    {
        $this->planned();
        $this->failing = ['com.docker.compose.project=project-next' => 'listing refused'];

        $this->assertSame(ZeroDowntimeRedeploy::IN_PLACE, $this->swap->begin());

        $this->assertContains('Replacing the running app in place after all: the second copy published no port Docker would name', $this->logged());
    }

    public function test_a_plan_abandoned_before_it_began_removes_its_copy(): void
    {
        $this->planned();
        (new GenerationState($this->username))->put(GenerationState::NEXT, ['project' => 'project-next', 'routes' => [], 'rules' => []]);

        $this->swap->abandon();

        $this->assertNotEmpty($this->ranMatching('account-docker.removeContainers'));
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
        $this->assertSame(['app'], $this->swap->generationServices());
    }

    public function test_a_webserver_the_engine_does_not_switch_replaces_in_place(): void
    {
        $this->webserverName = 'apache';
        $this->deploying();

        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project()));

        $this->assertContains('Replacing the running app in place, not beside it: the apache webserver is not one the engine switches between ports', $this->logged());
    }

    public function test_a_running_version_that_cannot_be_probed_is_replaced_in_place(): void
    {
        $this->failing = ['for port in' => 'probe refused'];
        $this->deploying();

        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project()));

        $this->assertContains('Replacing the running app in place, not beside it: the running version does not answer on port 8080 '
            . '(the probe could not run: probe refused), so there is nothing to keep serving', $this->logged());
    }

    /** Planned beside the running version, nothing started yet; the site rule's id. */
    private function planned(): int
    {
        $this->deploying();
        $rule = (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true, 'transport' => 'http',
            'listen_port' => 443, 'upstream_host' => $this->username, 'upstream_port' => 8080, 'is_generated' => true,
        ])->id;
        $this->snapshot();
        $this->swap = ZeroDowntimeRedeploy::plan($this->project());
        $this->assertInstanceOf(ZeroDowntimeRedeploy::class, $this->swap);

        return $rule;
    }

    /** A redeploy whose new version answered as a second copy and took the site; the site rule's id. */
    private function switched(): int
    {
        $this->deploying();
        $rule = (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true, 'transport' => 'http',
            'listen_port' => 443, 'upstream_host' => $this->username, 'upstream_port' => 8080, 'is_generated' => true,
        ])->id;
        $this->snapshot();
        $state = new GenerationState($this->username);
        $state->put(GenerationState::IMAGES, ['containers' => [['id' => 'old1', 'image' => self::OLD_IMAGE, 'ref' => 'project-app']]]);
        $state->put(GenerationState::CHECKOUT, ['path' => '/home/u/.project-prev', 'containers' => ['old1'], 'binds' => false, 'owner' => GenerationState::owner()]);

        $this->swap = ZeroDowntimeRedeploy::plan($this->project());
        $this->assertInstanceOf(ZeroDowntimeRedeploy::class, $this->swap);
        $this->assertSame(ZeroDowntimeRedeploy::SWITCHED, $this->swap->begin(new AppPortAlignment($this->project(), static function (int $seconds): void {
        })));
        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);

        return $rule;
    }

    private function deploying(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $this->deployId = $logger->getDeployId();
    }

    /** What a redeploy notes of the running version before it prepares the new one. */
    private function snapshot(): void
    {
        (new GenerationState($this->username))->put(GenerationState::ROUTES, [
            'details' => ['app_port' => 8080, 'git_commit' => 'aaaaaaa1111'],
            'containers' => ['old1'],
            'applied' => false,
            'gated' => false,
            'deploy' => $this->deployId,
            'owner' => GenerationState::owner(),
        ]);
    }

    private function project(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileGetContents')->willReturnCallback(
            static fn (string $path): string => $path === self::RUN_PATH ? "services:\n  app:\n    build: .\n    ports:\n      - \"8080:8080\"\n" : ''
        );
        $filesystem->method('directoryExists')->willReturn(true);
        $filesystem->method('writeFileReplacingPath')->willReturnCallback(function (): void {
            if ($this->overrideFails) {
                throw new \RuntimeException('disk full');
            }
        });
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturnCallback(fn (): string => $this->webserverName);
        $webserver->method('rebuildConfig')->willReturnCallback(function (): void {
            if ($this->reloadFailures > 0) {
                $this->reloadFailures--;
                throw new \RuntimeException('nginx reload failed');
            }
        });
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

        if ($this->user === null) {
            // Saved details stay in memory; no domain, so no Host header to probe with.
            $this->user = new class () extends User {
                public function save(array $options = []): bool
                {
                    return true;
                }

                public function getMainDomain(): ?Domain
                {
                    return null;
                }
            };
            $this->user->username = $this->username;
            $this->user->details = ['app_port' => 8080, 'deploy_strategy' => 'dockerfile', AppHealth::DETAIL_START_PERIOD => 30, 'git_commit' => 'bbbbbbb2222'];
        }
        $networking = $this->createStub(Networking::class);
        $networking->method('applyRoutes')->willReturnCallback(function (User $u, int $port): void {
            $this->routed[] = $port;
        });

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($this->user);
        $project->method('networking')->willReturn($networking);
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $project->method('userAppComposeFileToRun')->willReturn(self::RUN_PATH);
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('accountDaemon')->willReturn(new AccountDaemon($project));
        $project->method('userAppComposeProjectName')->willReturn('project');

        return $project;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = LocalRoot::composeLine($argv) ?: implode(' ', $argv);
        $this->ran[] = $line;
        foreach ($this->failing as $needle => $message) {
            if (str_contains($line, $needle)) {
                throw new \RuntimeException($message);
            }
        }
        $asked = array_slice(LocalRoot::values($argv), 1);
        if (LocalRoot::action($argv) === 'account-docker.images') {
            return (string) json_encode(array_map(fn (): ?array => $this->held ? ['Id' => self::OLD_IMAGE] : null, $asked));
        }
        if (str_contains($line, "'config' '--format' 'json'")) {
            return (string) json_encode([
                'name' => 'project',
                'networks' => ['default' => ['name' => 'project_default']],
                'services' => ['app' => [
                    'build' => ['context' => '/home/u/project', 'dockerfile' => 'Dockerfile'],
                    'networks' => ['default' => null],
                    'ports' => [['mode' => 'ingress', 'target' => 8080, 'published' => '8080', 'protocol' => 'tcp']],
                ]],
            ]);
        }
        if (str_contains($line, "'up' '-d' '--remove-orphans' '--no-build'")) {
            $this->previousUp = true;

            return '';
        }
        if (preg_match('/account-docker\.listContainers .*label com\.docker\.compose\.project=project(?: |$)/', $line) === 1) {
            return '[{"Id":"old1","Names":["/project-app-1"]}]';
        }
        if (str_contains($line, 'account-docker.listContainers') && str_contains($line, 'project-next')) {
            return '[{"Id":"next1"}]';
        }
        if (preg_match('/account-docker\.containers -- \S+ (.+)$/', $line, $m) === 1) {
            return (string) json_encode(array_map(static fn (string $container): array => [
                'State' => ['Status' => 'running', 'ExitCode' => 0],
                'RestartCount' => 0,
                'NetworkSettings' => [
                    'Networks' => ['project-next_default' => ['IPAddress' => TestNetwork::container(9)]],
                    'Ports' => $container === 'next1' ? ['8080/tcp' => [['HostIp' => TestNetwork::ANY, 'HostPort' => (string) self::COPY]]] : [],
                ],
            ], explode(' ', $m[1])));
        }
        if (str_contains($line, 'account-docker.listeningSockets')) {
            return self::HEADER . "\n   0: 00000000:1F90 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1\n";
        }
        if (str_contains($line, 'curl') && str_contains($line, ' probe ' . TestNetwork::container(9) . ' ')) {
            return '200';
        }
        if (str_contains($line, 'account-docker.logTail')) {
            return "replaced app printed this\n";
        }
        if (preg_match('/for port in ([\d ]+); do/', $line, $m) === 1) {
            $out = '';
            foreach (array_map('intval', explode(' ', trim($m[1]))) as $port) {
                $answers = $port === self::COPY ? $this->copyAnswers : ($this->previousUp ? $this->previousAnswers : $this->appAnswers);
                $out .= $answers
                    ? "{$port}\thttp\t200 0.010\t\t" . base64_encode('<html>ok</html>') . "\t/\n"
                    : "{$port}\thttp\t000 0.001\tRecv failure: Connection reset by peer\n";
            }

            return $out;
        }

        return '';
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
