<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\ProxyRule;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * engine#692: a redeploy whose process died. The sweep brings the previous
 * version back while what it needs is kept, keeps the new one only when it
 * passed its health check and still answers or nothing of the previous one
 * is left, and the deploy's log says which version serves and why.
 */
class GenerationSweepTest extends TestCase
{
    /** `docker compose up -d` as the account runs it, each word quoted. */
    private const UP = "'up' '-d'";

    private const OLD_IMAGE = 'sha256:bbbbbbbbbbbb0000000000000000000000000000000000000000000000000000';

    private string $username = '';

    private string $deployId = '';

    /** @var list<string> what the account and the host were asked to run */
    private array $ran = [];

    /** @var list<int> */
    private array $routed = [];

    private bool $oldRunning = false;

    private bool $held = true;

    private bool $asideThere = true;

    private int $appPort = 3000;

    /** @var array<int, int> port => the HTTP code it answers, with a page */
    private array $answers = [];

    /** @var list<int> ports that answer their code with an empty body */
    private array $blank = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'sweep-' . bin2hex(random_bytes(6));
        GenerationSweep::$newAnswerSeconds = 0;
        GenerationSweep::$backAnswerSeconds = 0;
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
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        Schema::dropIfExists('domains');
        Schema::dropIfExists('proxy_rules');
        GenerationSweep::$newAnswerSeconds = 20;
        GenerationSweep::$backAnswerSeconds = 10;
        Sleep::fake(false);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_decision_table(): void
    {
        $this->assertSame(GenerationSweep::PREVIOUS, GenerationSweep::decide(true, true, true, true), 'not replaced yet');
        $this->assertSame(GenerationSweep::PREVIOUS, GenerationSweep::decide(true, false, false, null));
        $this->assertSame(GenerationSweep::NEW, GenerationSweep::decide(false, true, true, true), 'proven and answering: kept');
        $this->assertSame(GenerationSweep::RESTORE, GenerationSweep::decide(false, true, true, false), 'proven once, silent now');
        $this->assertSame(GenerationSweep::RESTORE, GenerationSweep::decide(false, false, true, null), 'never proven');
        $this->assertSame(GenerationSweep::NEW, GenerationSweep::decide(false, false, false, true), 'nothing else is left');
        $this->assertSame(GenerationSweep::NEW, GenerationSweep::decide(false, true, false, false));
    }

    public function test_a_redeploy_that_died_before_replacing_anything_leaves_the_previous_version(): void
    {
        $this->interrupted(gated: true);
        $this->oldRunning = true;
        $this->answers = [3000 => 200];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertContains('deploy details restored', $done);
        $this->assertContains('checkout restored', $done);
        $this->assertNotRan(self::UP);
        $this->assertStringContainsString('the previous version (commit aaaaaaa) still serves on port 3000', $this->lastLine());
        $this->assertClosedAsInterrupted();
    }

    public function test_a_new_version_that_never_passed_its_health_check_is_replaced_by_the_previous_one(): void
    {
        $this->interrupted(gated: false);
        $this->answers = [3000 => 200];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertSame('previous version started again', $done[0]);
        $this->assertRan('docker tag "$2" "$1"');
        $this->assertRan('project-app panelalpha-serving:bbbbbbbbbbbb');
        $this->assertRan(self::UP . " '--remove-orphans' '--no-build'");
        $this->assertStringContainsString(
            'the previous version (commit aaaaaaa) was started again on port 3000, because the new version had not passed its health check; it answers',
            $this->lastLine()
        );
        $this->assertClosedAsInterrupted();
        $this->assertFileDoesNotExist(GenerationState::path($this->username));
    }

    public function test_a_new_version_that_passed_its_health_check_and_answers_is_kept(): void
    {
        $this->appPort = 8080;
        $site = $this->rule(true, 32771);
        $hand = $this->rule(false, 3000);
        $this->interrupted(gated: true, next: ['project' => 'project-next', 'routed' => 8080, 'routes' => [3000 => 32771], 'rules' => [$site]]);
        $this->answers = [8080 => 200];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertContains('second generation removed', $done);
        $this->assertNotRan(self::UP);
        $this->assertNotRan('docker tag "$2" "$1"');
        $this->assertSame(8080, ProxyRule::find($site)->upstream_port, 'on to the new version, not back to a port nothing serves');
        $this->assertSame(8080, ProxyRule::find($hand)->upstream_port, 'the operator\'s rule follows');
        $this->assertSame([8080], $this->routed);
        $this->assertStringContainsString(
            'the new version (commit bbbbbbb) serves on port 8080, because it passed its health check before the deploy stopped',
            $this->lastLine()
        );
        $this->assertStringEndsWith('; it answers', $this->lastLine());
        $this->assertClosedAsInterrupted();
    }

    /**
     * A port change 3000 -> 8080 killed after traffic was back on 8080: the
     * enabled rules got there through the second copy, the disabled one by
     * following the new version. All of them go back with the previous one.
     */
    public function test_a_new_version_that_stopped_answering_is_replaced_by_the_previous_one(): void
    {
        $this->appPort = 8080;
        $http = $this->rule(false, 8080);
        $tcp = $this->rule(false, 8080, 'tcp', 25691);
        $off = $this->rule(false, 8080, 'http', 18693, enabled: false);
        $edited = $this->rule(false, 9001, 'http', 18694);
        $this->interrupted(
            gated: true,
            applied: true,
            hand: [['id' => $http, 'port' => 3000], ['id' => $tcp, 'port' => 3000], ['id' => $off, 'port' => 3000], ['id' => $edited, 'port' => 3000]],
            followed: [$off],
        );
        $this->answers = [8080 => 500, 3000 => 200];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertSame('previous version started again', $done[0]);
        $this->assertRan(self::UP . " '--remove-orphans' '--no-build'");
        $this->assertSame(3000, ProxyRule::find($http)->upstream_port, 'back with the previous version');
        $this->assertSame(3000, ProxyRule::find($tcp)->upstream_port);
        $this->assertSame(3000, ProxyRule::find($off)->upstream_port);
        $this->assertFalse(ProxyRule::find($off)->enabled);
        $this->assertSame(9001, ProxyRule::find($edited)->upstream_port, 'its owner pointed it elsewhere meanwhile');
        $this->assertSame([3000], $this->routed);
        $this->assertStringContainsString(
            'because the new version passed its health check but no longer answers (it answers HTTP 500); it answers',
            $this->lastLine()
        );
    }

    /** Killed after the second copy's routes went to 8080 but before the new version was noted as taking traffic. */
    public function test_a_restore_moves_everything_back_even_before_the_new_version_was_noted_as_serving(): void
    {
        $this->appPort = 8080;
        $http = $this->rule(false, 8080);
        $this->interrupted(gated: true, applied: false, hand: [['id' => $http, 'port' => 3000]]);
        $this->answers = [8080 => 500, 3000 => 200];

        GenerationSweep::settleInterrupted($this->project());

        $this->assertSame(3000, ProxyRule::find($http)->upstream_port);
        $this->assertSame([3000], $this->routed, 'the site\'s own rules too');
    }

    /** A new version left serving an empty page is not one worth keeping. */
    public function test_a_new_version_that_answers_an_empty_page_is_replaced_by_the_previous_one(): void
    {
        $this->appPort = 8080;
        $this->interrupted(gated: true, applied: true);
        $this->answers = [8080 => 200, 3000 => 200];
        $this->blank = [8080];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertSame('previous version started again', $done[0]);
        $this->assertSame([3000], $this->routed);
        $this->assertStringContainsString(
            'because the new version passed its health check but no longer answers (it answers HTTP 200 with an empty page); it answers',
            $this->lastLine()
        );
    }

    public function test_with_nothing_of_the_previous_version_left_the_new_one_stays_and_the_log_says_it_does_not_answer(): void
    {
        $this->interrupted(gated: false);
        $this->held = false;
        $this->answers = [3000 => 500];

        GenerationSweep::settleInterrupted($this->project());

        $this->assertNotRan(self::UP);
        $this->assertStringContainsString(
            'the new version (commit bbbbbbb) serves on port 3000, because the previous one had been replaced, and its checkout or images are no longer there to start it again; it does not answer: it answers HTTP 500',
            $this->lastLine()
        );
        $this->assertClosedAsInterrupted();
    }

    /**
     * engine#757, interrupted: the new version took the site through its copy, replaced the running one and
     * does not answer, and the previous one cannot be started. The copy keeps the site, not the silent app.
     */
    public function test_with_nothing_of_the_previous_version_left_a_silent_new_version_does_not_take_the_site_from_its_copy(): void
    {
        $this->appPort = 8080;
        $site = $this->rule(true, 32771);
        $this->interrupted(gated: true, next: ['project' => 'project-next', 'routed' => 8080, 'routes' => [3000 => 32771], 'rules' => [$site]]);
        $this->held = false;
        $this->answers = [8080 => 500, 32771 => 200];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertNotContains('second generation removed', $done);
        $this->assertContains('routes left on the second copy', $done);
        $this->assertSame(32771, ProxyRule::find($site)->upstream_port, 'the site stays where it is served');
        $this->assertSame([], $this->routed);
        $this->assertNotRan('rm -f -v');
        $this->assertSame(8080, (new GenerationState($this->username))->get(GenerationState::NEXT)['back']);
        $this->assertSame(
            'The redeploy stopped before it finished: the new version (commit bbbbbbb) does not answer on port 8080 (it answers HTTP 500), and the previous one '
            . 'had been replaced and cannot be started again, so the new version\'s second copy keeps serving the site until the app answers',
            $this->lastLine()
        );

        // Once the app answers, the next sweep moves the site to it.
        $this->answers = [8080 => 200, 32771 => 200];
        $this->assertContains('second generation removed', GenerationSweep::settleInterrupted($this->project()));
        $this->assertSame(8080, ProxyRule::find($site)->upstream_port);
        $this->assertRan('rm -f -v');
    }

    /** The previous version is started again but does not answer: the copy keeps the site until it does. */
    public function test_a_previous_version_started_again_takes_the_site_from_the_copy_only_once_it_answers(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 18));
        Sleep::fake(syncWithCarbon: true);
        $site = $this->rule(true, 32771);
        $this->interrupted(gated: false, next: ['project' => 'project-next', 'routed' => 3000, 'routes' => [3000 => 32771], 'rules' => [$site]]);
        $this->answers = [3000 => 500, 32771 => 200];

        $done = GenerationSweep::settleInterrupted($this->project());

        $this->assertSame(['previous version started again'], $done);
        $this->assertRan(self::UP . " '--remove-orphans' '--no-build'");
        $this->assertSame(32771, ProxyRule::find($site)->upstream_port);
        $this->assertSame([], $this->routed, 'not routed to a version that does not answer');
        $this->assertNotRan('rm -f -v');
        $this->assertStringEndsWith(
            'because the new version had not passed its health check; it does not answer: it answers HTTP 500. The new version\'s second copy keeps serving the site until it does',
            $this->lastLine()
        );
    }

    public function test_a_deploy_killed_after_it_finished_is_settled_as_the_success_it_was(): void
    {
        $this->interrupted(gated: true, finished: true);
        $this->answers = [3000 => 500];

        GenerationSweep::settleInterrupted($this->project());

        $this->assertNotRan(self::UP);
        $this->assertNotRan('curl');
        $this->assertSame(DeployLogger::STATUS_SUCCESS, DeployLogger::readLatestFor($this->username)['status']);
        $this->assertSame('Deploy finished successfully', $this->lastLine());
    }

    /**
     * @param ?array<string, mixed> $next
     * @param list<array{id: int, port: int}> $hand
     * @param list<int> $followed what an earlier engine noted instead
     */
    private function interrupted(bool $gated, ?array $next = null, bool $applied = false, array $hand = [], array $followed = [], bool $finished = false): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        if ($finished) {
            (new \ReflectionMethod(DeployLogger::class, 'writeLine'))->invoke($logger, DeployLogger::LEVEL_OK, 'Deploy finished successfully', DeployLogger::STAGE_RUNNING);
        }
        $this->deployId = $logger->getDeployId();
        unset($logger);
        gc_collect_cycles();

        $gone = ['pid' => 999999, 'start' => null, 'at' => time() - 30];
        $state = new GenerationState($this->username);
        $state->put(GenerationState::ROUTES, [
            'details' => ['app_port' => 3000, 'git_commit' => 'aaaaaaa1111'],
            'containers' => ['old1'],
            'applied' => $applied,
            'gated' => $gated,
            'deploy' => $this->deployId,
            'owner' => $gone,
        ] + ($hand === [] ? [] : ['hand' => $hand]) + ($followed === [] ? [] : ['followed' => $followed]));
        $state->put(GenerationState::IMAGES, ['containers' => [['id' => 'old1', 'image' => self::OLD_IMAGE, 'ref' => 'project-app']]]);
        $state->put(GenerationState::CHECKOUT, ['path' => '/home/u/.project-prev', 'containers' => ['old1'], 'binds' => false, 'owner' => $gone]);
        if ($next !== null) {
            $state->put(GenerationState::NEXT, $next);
        }
    }

    private function rule(bool $generated, int $port, string $transport = 'http', ?int $listen = null, bool $enabled = true): int
    {
        return (int) ProxyRule::query()->create([
            'owner_scope' => 'user', 'username' => $this->username, 'enabled' => $enabled, 'transport' => $transport,
            'listen_port' => $listen ?? ($generated ? 443 : 18691), 'upstream_host' => $this->username,
            'upstream_port' => $port, 'is_generated' => $generated,
        ])->id;
    }

    private function project(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('directoryExists')->willReturnCallback(fn (): bool => $this->asideThere);
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('webserver')->willReturn($this->createStub(Webserver::class));
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->account(is_array($cmd) ? $cmd : [$cmd]));

        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturnCallback(fn (): int => $this->appPort);
        $user->method('getDetails')->willReturn(['git_commit' => 'bbbbbbb2222']);
        $user->method('getMainDomain')->willReturn(null);
        $networking = $this->createStub(Networking::class);
        $networking->method('applyRoutes')->willReturnCallback(function (User $u, int $port): void {
            $this->routed[] = $port;
        });

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('networking')->willReturn($networking);
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
        if (in_array('retag', $argv, true)) {
            return '0';
        }
        if (str_contains($line, '{{.State.Running}}')) {
            return $this->oldRunning ? "true\n" : "gone\n";
        }
        if (str_contains($line, 'echo held')) {
            return $this->held ? 'held' : 'gone';
        }
        if (str_contains($line, 'docker tag "$2" "$1"')) {
            return '1';
        }
        if (str_contains($line, 'publish=')) {
            return 'sha256:cccccccccccc0000';
        }
        if (str_contains($line, 'curl')) {
            return implode("\n", array_map(
                fn (int $port, int $code): string => "{$port}\thttp\t{$code} 0.010\t\t"
                    . (in_array($port, $this->blank, true) ? '' : base64_encode("<html>{$code}</html>")) . "\t/",
                array_keys($this->answers),
                $this->answers
            )) . "\n";
        }

        return '';
    }

    private function assertRan(string $needle): void
    {
        $this->assertNotEmpty(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)), "ran: {$needle}");
    }

    private function assertNotRan(string $needle): void
    {
        $this->assertEmpty(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)), "did not run: {$needle}");
    }

    private function lastLine(): string
    {
        return (string) DeployLogger::forDeploy($this->username, $this->deployId)->tail(1)[0]['msg'];
    }

    private function assertClosedAsInterrupted(): void
    {
        $latest = DeployLogger::readLatestFor($this->username);
        $this->assertSame(DeployLogger::STATUS_FAILED, $latest['status']);
        $this->assertSame(DeployLogger::INTERRUPTED_MESSAGE, $latest['error']);
    }
}
