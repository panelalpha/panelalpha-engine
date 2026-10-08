<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\Networking;
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
 * The new version answered as a second copy, the site moved to it, and the app's own
 * services were replaced by a version that does not answer on its port. The site never goes to it: the
 * previous version is started again and takes the site once it answers, and while nothing else answers
 * the copy keeps serving, through the deploy's own settle, the sweep and the next deploy.
 */
class ReplacedAppSilentTest extends TestCase
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

    /** Whether the app's own port answers: the running version until it is replaced, then the replaced app. */
    private bool $appAnswers = true;

    /** Whether the previous version, once started again, answers. */
    private bool $previousAnswers = true;

    private bool $previousUp = false;

    private bool $copyAnswers = true;

    /** Whether the images of the previous version are still held by their tags. */
    private bool $held = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'silent-' . bin2hex(random_bytes(6));
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

    public function test_a_replaced_app_that_answers_takes_the_site_back(): void
    {
        $rule = $this->switched();

        $this->assertNull($this->swap->finish(true));

        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertNotEmpty($this->ranMatching('docker rm -f'), 'the copy is removed');
        $this->assertSame([], $this->ranMatching("'--remove-orphans' '--no-build'"), 'nothing started again');
        $this->assertContains("Traffic is back on the app's own port 8080; the second copy was removed", $this->logged());
    }

    /** The case of the issue: the site goes to the previous version, never to the silent app. */
    public function test_a_replaced_app_that_does_not_answer_is_rolled_back_to_the_previous_version(): void
    {
        $rule = $this->switched();
        $this->appAnswers = false;

        $result = $this->swap->finish(true);

        $this->assertIsArray($result);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame(
            'The new version did not answer on its own port 8080 once it replaced the running one: nothing answered within 30 s '
            . '(Recv failure: Connection reset by peer). The previous version was started again and serves on port 8080.',
            $result['stderr']
        );
        $this->assertNotEmpty($this->ranMatching("'up' '-d' '--remove-orphans' '--no-build'"), 'the previous version is started again');
        $this->assertNotEmpty($this->ranMatching('project-app panelalpha-serving:bbbbbbbbbbbb'), 'from its held image');
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([8080], $this->routed, 'the site\'s rules on the previous version\'s port');
        $this->assertNotEmpty($this->ranMatching('docker rm -f'), 'the copy goes once the previous version serves');
        $this->assertFileDoesNotExist(GenerationState::path($this->username), 'nothing left for a settle or the sweep');
        $this->assertContains('The replaced app does not answer on port 8080: nothing answered within 30 s (Recv failure: Connection reset by peer). '
            . 'The second copy keeps serving while the previous version is started again', $this->logged());
        $this->assertContains('The previous version was started again and answers on port 8080; traffic moved to it and the second copy was removed', $this->logged());
        $this->assertContains('replaced app printed this', $this->logged());
        $this->assertSame('The previous version was started again and serves; the new version\'s second copy was removed', ZeroDowntimeRedeploy::keptServing($result));
    }

    /** @return iterable<string, array{0: bool, 1: bool, 2: string}> images held, previous answers, why it could not be started */
    public static function previousCannotServe(): iterable
    {
        yield 'its images are gone' => [false, true, 'its checkout or images are no longer kept'];
        yield 'it does not answer once started' => [true, false, 'Recv failure: Connection reset by peer'];
    }

    /** Nothing else answers: the copy keeps the site, and no later settle takes it away while the app is silent. */
    #[DataProvider('previousCannotServe')]
    public function test_when_the_previous_version_cannot_serve_the_copy_keeps_the_site(bool $held, bool $answers, string $why): void
    {
        $rule = $this->switched();
        $this->appAnswers = false;
        $this->held = $held;
        $this->previousAnswers = $answers;

        $result = $this->swap->finish(true);

        $this->assertIsArray($result);
        $this->assertTrue($result[ZeroDowntimeRedeploy::COPY_KEPT]);
        $this->assertArrayNotHasKey(ZeroDowntimeRedeploy::PREVIOUS_KEPT, $result);
        $this->assertStringContainsString("The previous version could not be started again ({$why}", $result['stderr']);
        $this->assertStringEndsWith('so the new version\'s second copy keeps serving the site on port 32768 until the app answers on port 8080.', $result['stderr']);
        $this->assertSame('The new version\'s second copy keeps serving the site; nothing was torn down', ZeroDowntimeRedeploy::keptServing($result));
        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port, 'the site stays on the copy');
        $this->assertSame([], $this->ranMatching('docker rm -f'), 'the copy is not removed');
        $this->assertSame([], $this->routed);
        $state = new GenerationState($this->username);
        $this->assertSame(8080, $state->get(GenerationState::NEXT)['back']);
        $this->assertNull($state->get(GenerationState::ROUTES), 'nothing routes the site away from the copy');

        // The deploy's own settle, then the minute sweep: the copy stays while the app is silent.
        $this->assertNotNull(GenerationSweep::settleIfIdle($this->project(), false));
        GenerationSweep::settleInterrupted($this->project());
        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->ranMatching('docker rm -f'));
        $this->assertSame([], $this->routed);

        // Once the app answers, the next settle moves the site there and removes the copy.
        $this->appAnswers = true;
        $this->previousUp = false;
        $this->assertContains('second generation removed', GenerationSweep::settleIfIdle($this->project(), false));
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertNotEmpty($this->ranMatching('docker rm -f'));
    }

    /** The replace itself failed and the running version is gone: rolled back the same way. */
    public function test_a_failed_replace_that_left_nothing_answering_is_rolled_back(): void
    {
        $rule = $this->switched();
        $this->appAnswers = false;

        $result = $this->swap->finish(false, "Error response from daemon: driver failed programming external connectivity\n");

        $this->assertIsArray($result);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
        $this->assertStringStartsWith('The new version could not replace the running app (Error response from daemon: driver failed programming external connectivity), '
            . 'and nothing answers on port 8080 any more. The previous version was started again and serves on port 8080.', $result['stderr']);
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
    }

    public function test_a_failed_replace_with_the_running_version_still_answering_goes_back_to_it(): void
    {
        $rule = $this->switched();

        $this->assertNull($this->swap->finish(false, 'compose failed'));

        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->ranMatching("'--remove-orphans' '--no-build'"));
        $this->assertNotEmpty($this->ranMatching('docker rm -f'));
    }

    /** A deploy stopped mid-replace: traffic does not go back to a port that no longer answers. */
    public function test_an_abandoned_switch_keeps_the_copy_while_the_app_is_silent(): void
    {
        $rule = $this->switched();
        $this->appAnswers = false;

        $this->swap->abandon();

        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->ranMatching('docker rm -f'));
        $this->assertContains('Traffic stays on the second copy: nothing answers on port 8080', $this->logged());
    }

    /** The next deploy finds the copy still serving: in place, and the site moves only once the new version answers. */
    public function test_the_next_deploy_leaves_a_serving_copy_until_its_new_version_answers(): void
    {
        $rule = $this->switched();
        $this->appAnswers = false;
        $this->held = false;
        $this->swap->finish(true);
        $this->ran = [];

        $this->deploying();
        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project()), 'replaced in place');
        $this->assertContains('Replacing the running app in place, not beside it: a second copy an earlier redeploy left still serves the site, '
            . 'and it does until the new version answers', $this->logged());
        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->ranMatching('docker rm -f'));

        // In place, the new version has not answered yet: the site stays on the copy.
        $this->snapshot();
        (new RoutingSnapshot($this->project()))->apply();
        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([], $this->routed);
        $this->assertTrue(RoutingSnapshot::defers($this->username));

        // It answers: the site moves to it and the copy goes.
        $this->appAnswers = true;
        (new RoutingSnapshot($this->project()))->apply();
        $this->assertSame(8080, ProxyRule::find($rule)->upstream_port);
        $this->assertSame([8080], $this->routed);
        $this->assertNotEmpty($this->ranMatching('docker rm -f'));
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::NEXT));
    }

    /** A copy holds the site and the app does not run: a redeploy's port detection does not move the site to it. */
    public function test_routing_waits_while_a_copy_holds_the_site(): void
    {
        $rule = $this->keptCopy();
        $this->deploying();

        $this->assertTrue(RoutingSnapshot::defers($this->username));
        (new Networking($this->project()))->routeTo($this->user, 8080);

        $this->assertSame(self::COPY, ProxyRule::find($rule)->upstream_port);
        $this->assertContains('The site stays on the second copy an earlier redeploy left, until a version answers on its own port', $this->logged());
    }

    /** Whether the copy holds the site is decided by what answers, not by where its rows point now. */
    public function test_a_copy_whose_rules_were_pointed_elsewhere_stays_while_it_alone_answers(): void
    {
        $rule = $this->keptCopy();
        ProxyRule::query()->whereKey($rule)->update(['upstream_port' => 8080]);
        $this->ran = [];
        $this->deploying();

        $this->assertNull(ZeroDowntimeRedeploy::plan($this->project()));

        $this->assertSame([], $this->ranMatching('docker rm -f'), 'the copy is not removed');
        $this->assertTrue(ZeroDowntimeRedeploy::copyHeld($this->username));
        $this->assertContains('Replacing the running app in place, not beside it: a second copy an earlier redeploy left still serves the site, '
            . 'and it does until the new version answers', $this->logged());
    }

    /** A redeploy from that state fails: the deploy tears nothing down, and the copy keeps the site. */
    public function test_a_failed_start_while_a_copy_holds_the_site_tears_nothing_down(): void
    {
        $this->keptCopy();
        $failed = ['stdout' => '', 'stderr' => 'build failed', 'exit_code' => 1];

        $result = ZeroDowntimeRedeploy::withHeldCopy($this->username, $failed);

        $this->assertTrue($result[ZeroDowntimeRedeploy::COPY_KEPT]);
        $this->assertSame('The second copy an earlier redeploy left keeps serving the site; nothing was torn down', ZeroDowntimeRedeploy::keptServing($result));
        $this->assertSame(['exit_code' => 0], ZeroDowntimeRedeploy::withHeldCopy($this->username, ['exit_code' => 0]));
        $this->assertNull(ZeroDowntimeRedeploy::keptServing(ZeroDowntimeRedeploy::withHeldCopy('nobody-' . bin2hex(random_bytes(3)), $failed)));
    }

    /** The state the issue ends in once the previous version cannot serve: the copy alone holds the site. */
    private function keptCopy(): int
    {
        $rule = $this->switched();
        $this->appAnswers = false;
        $this->held = false;
        $this->assertTrue($this->swap->finish(true)[ZeroDowntimeRedeploy::COPY_KEPT]);

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

        return $project;
    }

    /** @param list<string> $argv */
    private function account(array $argv): string
    {
        $line = implode(' ', $argv);
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
        if (str_contains($line, "'up' '-d' '--remove-orphans' '--no-build'")) {
            $this->previousUp = true;

            return '';
        }
        if (str_contains($line, "'ps' '--quiet' '--status' 'running'")) {
            return "old1\n";
        }
        if (str_contains($line, 'docker ps -aq') && str_contains($line, 'project-next')) {
            return "next1\n";
        }
        if (str_contains($line, 'docker port next1 8080/tcp')) {
            return '0.0.0.0:' . self::COPY . "\n";
        }
        if (str_contains($line, 'net/tcp')) {
            return self::HEADER . "\n   0: 00000000:1F90 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1\n";
        }
        if (str_contains($line, 'NetworkSettings.Networks')) {
            return '200';
        }
        if (str_contains($line, '{{.State.Status}} {{.State.ExitCode}} {{.RestartCount}}')) {
            return 'running 0 0';
        }
        if (str_contains($line, 'echo held')) {
            return $this->held ? 'held' : 'gone';
        }
        if (str_contains($line, 'docker tag "$2" "$1"') || in_array('retag', $argv, true)) {
            return '1';
        }
        if (str_contains($line, 'docker logs')) {
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
