<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppCertificate;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * start() on a redeploy that goes beside the running version: the build while it serves, the switch
 * to the second copy, the replace behind it and the way back, and a swap abandoned when a step throws.
 */
class AppLauncherSwapTest extends TestCase
{
    private const RUN_PATH = '/home/u/project/docker-compose.panelalpha.yml';

    private const COPY = 32768;

    private const HEADER =
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode';

    private const BUILD = "'build'";

    private const COPY_UP = "'--project-name' 'project-next' 'up'";

    private const REPLACE = "'up' '-d' '--remove-orphans' '--no-build'";

    private string $username = '';

    private string $deployId = '';

    private ?User $user = null;

    /** @var list<string> */
    private array $ran = [];

    /** @var array<string, string> a compose line fragment => the shell script a process carrying it runs */
    private array $scripts = [];

    /** A compose line fragment whose process throws instead of running. */
    private ?string $throwOn = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'swap-' . bin2hex(random_bytes(6));
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
        Schema::dropIfExists('proxy_rules');
        parent::tearDown();
    }

    public function test_the_new_version_takes_the_site_beside_the_running_one_and_hands_it_back_once_replaced(): void
    {
        $rule = $this->redeploying();

        $result = $this->launcher()->start();

        $this->assertSame(0, $result['exit_code']);
        $this->assertCount(1, array_keys($this->ran, "'docker' 'compose' 'build'", true));
        $this->assertCount(1, $this->ranMatching(self::COPY_UP));
        $this->assertCount(1, $this->ranMatching(self::REPLACE));
        $this->assertSame([], $this->ranMatching("'--force-recreate'"));
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $logged = $this->logged();
        $this->assertContains('Starting application (beside the running one, which serves until the new version answers): docker compose build', $logged);
        $this->assertContains('Replacing the running app behind the new version (docker compose up -d)', $logged);
        $this->assertContains("Traffic is back on the app's own port 8080; the second copy was removed", $logged);
    }

    public function test_a_start_asked_to_recreate_recreates_the_apps_own_services_behind_the_copy(): void
    {
        $this->redeploying();

        $result = $this->launcher()->start(forceRecreate: true);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(
            ["'docker' 'compose' 'up' '-d' '--no-build' '--no-deps' '--force-recreate' 'app'"],
            $this->ranMatching("'--force-recreate'")
        );
        $this->assertContains('Recreating app on the new files (docker compose up -d --force-recreate)', $this->logged());
    }

    public function test_a_build_that_fails_beside_the_running_version_leaves_it_serving(): void
    {
        $rule = $this->redeploying();
        $this->scripts[self::BUILD] = 'echo "#9 ERROR: process did not complete successfully" ; echo "exit code: 2" >&2; exit 2';

        $result = $this->launcher()->start();

        $this->assertSame(2, $result['exit_code']);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertStringContainsString('exit code: 2', $result['stderr']);
        $this->assertSame([], $this->ranMatching(self::COPY_UP), 'no second copy is started');
        $this->assertSame([], $this->ranMatching(self::REPLACE));
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
    }

    public function test_a_build_that_throws_beside_the_running_version_is_abandoned_and_rethrown(): void
    {
        $rule = $this->redeploying();
        $this->throwOn = self::BUILD;

        $this->assertSame('compose died', $this->thrownByStart());

        $this->assertSame([], $this->ranMatching(self::COPY_UP));
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
    }

    public function test_a_replace_that_throws_sends_the_site_back_and_removes_the_copy(): void
    {
        $rule = $this->redeploying();
        $this->throwOn = self::REPLACE;

        $this->assertSame('compose died', $this->thrownByStart());

        $this->assertCount(1, $this->ranMatching(self::COPY_UP));
        $this->assertContains('Traffic moved to the new version on port 32768; the running one finishes its requests', $this->logged());
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port, 'traffic went back to the running version');
        $this->assertNotEmpty($this->ranMatching('account-docker.removeContainers'), 'the copy is removed');
    }

    /** The message start() threw with; '' when it returned. */
    private function thrownByStart(): string
    {
        try {
            $this->launcher()->start();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return '';
    }

    /** A running version on 8080 behind the site's rule; the rule's id. */
    private function redeploying(): int
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $this->deployId = $logger->getDeployId();
        $rule = (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => true, 'transport' => 'http',
            'listen_port' => 443, 'upstream_host' => $this->username, 'upstream_port' => 8080, 'is_generated' => true,
        ])->id;
        (new GenerationState($this->username))->put(GenerationState::ROUTES, [
            'details' => ['app_port' => 8080, 'git_commit' => 'aaaaaaa1111'],
            'containers' => ['old1'],
            'applied' => false,
            'gated' => false,
            'deploy' => $this->deployId,
            'owner' => GenerationState::owner(),
        ]);

        return $rule;
    }

    private function launcher(): AppLauncher
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileGetContents')->willReturnCallback(
            static fn (string $path): string => $path === self::RUN_PATH ? "services:\n  app:\n    build: .\n    ports:\n      - \"8080:8080\"\n    restart: unless-stopped\n" : ''
        );
        $filesystem->method('directoryExists')->willReturn(true);
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('getCurrentWebserver')->willReturn('nginx-proxy');
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('webserver')->willReturn($webserver);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account(is_array($cmd) ? $cmd : [$cmd]));
        $system->method('runProcessWithCallbacks')->willReturnCallback(fn (array $cmd): Process => $this->process($cmd));

        if ($this->user === null) {
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

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($this->user);
        $project->method('networking')->willReturn($this->createStub(Networking::class));
        $project->method('innerDocker')->willReturn($this->createStub(InnerDocker::class));
        $project->method('appHealth')->willReturn($this->createStub(AppHealth::class));
        $project->method('environment')->willReturn($this->createStub(ProjectEnvironment::class));
        $project->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $project->method('userAppComposeFileToRun')->willReturn(self::RUN_PATH);
        $project->method('userAppComposeCommand')->willReturnCallback(fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('accountDaemon')->willReturn(new AccountDaemon($project));
        $project->method('userAppComposeProjectName')->willReturn('project');
        $project->method('registryLogin')->willReturn(new RegistryLogin($project));
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));
        $project->method('appCertificate')->willReturnCallback(static fn (): AppCertificate => new AppCertificate($project));

        return new AppLauncher($project);
    }

    /** @param list<string> $cmd */
    private function process(array $cmd): Process
    {
        $this->account($cmd);
        $line = LocalRoot::composeLine($cmd);
        $script = 'exit 0';
        foreach ($this->scripts as $fragment => $run) {
            if (str_contains($line, $fragment)) {
                $script = $run;
            }
        }
        if ($this->throwOn !== null && str_contains($line, $this->throwOn)) {
            throw new \RuntimeException('compose died');
        }
        $process = new Process(['sh', '-c', $script]);
        $process->run();

        return $process;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = LocalRoot::composeLine($argv) ?: implode(' ', $argv);
        $this->ran[] = $line;
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
        if (preg_match('/account-docker\.listContainers .*label com\.docker\.compose\.project=project(?: |$)/', $line) === 1) {
            return '[{"Id":"old1","Names":["/project-app-1"]}]';
        }
        if (str_contains($line, 'account-docker.listContainers') && str_contains($line, 'project-next')) {
            return '[{"Id":"next1"}]';
        }
        if (preg_match('/account-docker\.containers -- \S+ (.+)$/', $line, $m) === 1) {
            return (string) json_encode(array_map(static fn (string $container): array => [
                'Name' => '/project-app-1',
                'State' => ['Status' => 'running', 'ExitCode' => 0],
                'RestartCount' => 0,
                'Config' => ['Labels' => ['com.docker.compose.service' => 'app']],
                'NetworkSettings' => [
                    'Networks' => ['project-next_default' => ['IPAddress' => TestNetwork::private(9)]],
                    'Ports' => $container === 'next1' ? ['8080/tcp' => [['HostIp' => TestNetwork::ANY, 'HostPort' => (string) self::COPY]]] : [],
                ],
            ], explode(' ', $m[1])));
        }
        if (str_contains($line, 'account-docker.listeningSockets')) {
            return self::HEADER . "\n   0: 00000000:1F90 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1\n";
        }
        if (str_contains($line, 'curl') && str_contains($line, ' probe ' . TestNetwork::private(9) . ' ')) {
            return '200';
        }
        if (preg_match('/for port in ([\d ]+); do/', $line, $m) === 1) {
            $out = '';
            foreach (array_map('intval', explode(' ', trim($m[1]))) as $port) {
                $out .= "{$port}\thttp\t200 0.010\t\t" . base64_encode('<html>ok</html>') . "\t/\n";
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
        return array_map(static fn (array $entry): string => (string) $entry['msg'], DeployLogger::forDeploy($this->username, $this->deployId)->tail(300));
    }
}
