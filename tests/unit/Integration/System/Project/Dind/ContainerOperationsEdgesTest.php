<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\InnerBuildGuard;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Project and service actions around the compose call itself: refused actions, the run file
 * regenerated before a compose project's `up`, cleanups that fail quietly, a stop that fails loudly,
 * and the last partial line of a followed log.
 */
class ContainerOperationsEdgesTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    /** Compose subcommands that fail. */
    private array $failing = [];

    private bool $pruneFails = false;

    /** @var list<array{string, ?string}> */
    private array $refreshed = [];

    private ?string $strategy = null;

    private bool $markFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['env.DIND_RUNTIME' => 'privileged']);
    }

    public function test_an_action_that_is_not_one_is_refused_before_anything_runs(): void
    {
        $operations = $this->operations();

        try {
            $operations->projectAction('exec');
            $this->fail('a project action that is not one ran');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid project action: exec', $e->getMessage());
        }
        try {
            $operations->serviceAction('app', 'down');
            $this->fail('a service action that is not one ran');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid service action: down', $e->getMessage());
        }
        $this->assertSame([], $this->ran);
    }

    public function test_a_compose_projects_up_regenerates_its_run_file_first(): void
    {
        $this->strategy = Strategies::COMPOSE;

        $result = $this->operations()->projectAction('up');

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame([['/home/alice/project', '1001:1001']], $this->refreshed);
        $this->assertContains('compose up', $this->ran);
    }

    public function test_a_down_whose_volume_cleanup_fails_still_succeeds(): void
    {
        $this->pruneFails = true;

        $result = $this->operations()->projectAction('down');

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(['compose down', 'account-docker.pruneAnonymousVolumes'], $this->ran);
    }

    public function test_a_start_whose_stopped_flag_cannot_be_cleared_still_succeeds(): void
    {
        $this->markFails = true;

        $result = $this->operations()->projectAction('restart');

        $this->assertSame(0, $result['exit_code']);
        $this->assertContains('compose restart', $this->ran);
    }

    public function test_a_stop_that_fails_is_thrown_with_the_compose_error(): void
    {
        $this->failing = ['stop'];

        try {
            $this->operations()->composeStop();
            $this->fail('a failed stop returned');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('compose stop failed: ', $e->getMessage());
            $this->assertStringContainsString('cannot stop: daemon busy', $e->getMessage());
        }
    }

    public function test_a_followed_log_ending_mid_line_hands_over_the_rest(): void
    {
        $lines = [];

        $this->operations()->followServiceLogs('app', 10, null, function (string $line, ?string $at) use (&$lines): void {
            $lines[] = [$line, $at];
        });

        $this->assertSame([
            ['listening on 3000', '2026-11-20T08:00:00.000000000Z'],
            ['request served', '2026-11-20T08:00:01.000000000Z'],
        ], $lines);
    }

    private function operations(): ContainerOperations
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = array_values((array) $cmd);
            $action = (string) LocalRoot::action($argv);
            if (str_starts_with($action, 'account-docker.')) {
                $this->ran[] = $action;
                if ($this->pruneFails && $action === 'account-docker.pruneAnonymousVolumes') {
                    throw new \RuntimeException('prune refused');
                }

                return '';
            }
            if (preg_grep('/^build-guard/', $argv)) {
                return '';
            }
            $sub = LocalRoot::composeCommand($argv)[2] ?? '?';
            $this->ran[] = 'compose ' . $sub;
            if (in_array($sub, $this->failing, true)) {
                throw new \RuntimeException('cannot stop: daemon busy');
            }

            return '';
        });
        $system->method('runProcessWithCallbacks')->willReturnCallback(
            function (array $cmd, array $env, int $timeout, ?callable $onStart = null, ?callable $onOutput = null): Process {
                $onOutput(Process::OUT, "2026-11-20T08:00:00.000000000Z listening on 3000\n2026-11-20T08:00:01.000000000Z req");
                $onOutput(Process::OUT, 'uest served');

                return $this->createStub(Process::class);
            }
        );

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder('8192m', ''));

        $user = $this->createStub(ModelsUser::class);
        $user->method('effectiveMemoryLimit')->willReturn(1024);
        $user->method('getDeployStrategy')->willReturn($this->strategy);
        $user->method('getChownString')->willReturn('1001:1001');
        if ($this->markFails) {
            $user->method('markAppStoppedByRequest')->willThrowException(new \RuntimeException('database gone'));
        }

        $refreshed = &$this->refreshed;
        $strategy = $this->createStub(DeployStrategy::class);
        $strategy->method('refreshComposeRunFile')->willReturnCallback(function (string $dir, ?string $chown) use (&$refreshed): void {
            $refreshed[] = [$dir, $chown];
        });

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('engine')->willReturn($engine);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('userAppDirPath')->willReturn('/home/alice/project');
        $project->method('composeFilePath')->willReturn('/opt/users/alice/docker-compose.yml');
        $project->method('userModel')->willReturn($user);
        $project->method('strategy')->willReturn($strategy);
        $project->method('userAppComposeCommand')->willReturnCallback(
            static fn (array $rest): array => ['docker', 'compose', ...$rest]
        );
        $shell = new ShellOperations($project);
        $project->method('shell')->willReturn($shell);
        $project->method('innerBuildGuard')->willReturn(new InnerBuildGuard($project));
        $project->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($project));

        return new ContainerOperations($project, $shell);
    }
}
