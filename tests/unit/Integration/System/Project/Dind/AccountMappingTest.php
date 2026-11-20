<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\System;
use App\System\Project\Deployment\DindDeployMechanics;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountMapping;
use App\System\Project\Dind\OuterLifecycle;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * An account sysbox started unmapped reads /root as nobody's; only recreating
 * its container maps it again, and an account that cannot be asked is left alone.
 */
class AccountMappingTest extends TestCase
{
    private const COMPOSE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml';

    private string $storage;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<string|\Throwable> what each `stat` of /root answers, in turn */
    private array $owners = [];

    private int $awaited = 0;

    /** what a plain `compose up` of the account throws, if anything */
    private ?\Throwable $upError = null;

    /** @var array{container: string, sysbox: string, sysbox-fs: string} when the account container, sysbox-mgr and sysbox-fs last started */
    private array $started = ['container' => '2026-10-10T15:00:00.123456789Z', 'sysbox' => 'Sat 2026-10-10 15:02:45 UTC', 'sysbox-fs' => 'Sat 2026-10-10 14:00:00 UTC'];

    /** @var array{sysbox-fs: string, sysbox-mgr: string} what systemd reports each daemon's ActiveState as */
    private array $states = ['sysbox-fs' => 'active', 'sysbox-mgr' => 'active'];

    /** what asking the host for the daemons throws, if anything */
    private ?\Throwable $hostError = null;

    /** when a recreated account container started, if the test moves it */
    private ?string $startedWhenReady = null;

    private const EXEC_REFUSED = 'OCI runtime exec failed: exec failed: unable to start container process: unsafe procfs detected: openat2 fsmount:fscontext:proc/thread-self/fd/: operation not permitted';

    /** What starting a stopped container sysbox forgot answers. */
    private const START_REFUSED = "Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: container_linux.go:439: starting container process caused: process_linux.go:608: container init caused: rootfs_linux.go:76: setting up rootfs mounts caused: open etc/docker/daemon.json: permission denied\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/pa-mapping-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs/deploy', 0777, true);
        $this->app->useStoragePath($this->storage);
        config(['env.DIND_RUNTIME' => 'sysbox-runc']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_root_owning_root_is_mapped(): void
    {
        $this->owners = ["0\n"];
        $mapping = new AccountMapping($this->project());

        $this->assertFalse($mapping->isUnmapped());
    }

    public function test_nobody_owning_root_is_unmapped(): void
    {
        $this->owners = ["65534\n"];
        $mapping = new AccountMapping($this->project());

        $this->assertTrue($mapping->isUnmapped());
        $this->assertSame(['stat', '-c', '%u', '/root'], array_slice($this->commands[0], -4));
    }

    public function test_a_probe_that_fails_is_not_unmapped_and_recreates_nothing(): void
    {
        $this->owners = [new \RuntimeException('service "dind" is not running')];
        $mapping = new AccountMapping($this->project());

        $this->assertFalse($mapping->ensureMapped());
        $this->assertSame([], $this->recreates());

        $this->owners = ['OCI runtime exec failed'];
        $this->assertNull($mapping->isUnmapped());
    }

    public function test_an_account_refusing_exec_after_a_sysbox_restart_is_unmapped(): void
    {
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED)];
        $mapping = new AccountMapping($this->project());

        $this->assertTrue($mapping->isUnmapped());
    }

    public function test_an_account_started_since_sysbox_last_started_refusing_exec_is_not_called_unmapped(): void
    {
        $this->started['container'] = '2026-10-10T15:10:00.5Z';
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED)];

        $this->assertNull((new AccountMapping($this->project()))->isUnmapped());
        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());
        $this->assertSame([], $this->recreates());
    }

    public function test_unknown_start_times_keep_an_exec_refusal_unmapped(): void
    {
        $this->started = ['container' => '', 'sysbox' => '', 'sysbox-fs' => ''];
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED)];

        $this->assertTrue((new AccountMapping($this->project()))->isUnmapped());
    }

    public function test_a_mapped_account_started_before_sysbox_fs_last_started_is_recreated_once(): void
    {
        Log::spy();
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:05:00 UTC';
        $this->startedWhenReady = '2026-10-10T15:20:00.5Z';
        $this->owners = ['0', '0', '0', '0'];

        $this->assertTrue((new AccountMapping($this->project()))->ensureMapped());
        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped(), 'its new container started after sysbox-fs');

        $this->assertCount(1, $this->recreates());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => $message === 'alice: Recreated the account container: '
                . 'sysbox-fs no longer serves its mounts; sysbox was restarted while it ran'
        );
    }

    public function test_one_host_probe_reads_both_daemons_for_a_check(): void
    {
        $this->owners = ['0'];

        $this->assertFalse((new AccountMapping($this->project()))->isUnmapped());

        $this->assertSame(
            [['systemctl', 'show', 'sysbox-fs', 'sysbox-mgr', '--property=Id,LoadState,ActiveState,ActiveEnterTimestamp', '--timestamp=utc']],
            $this->sysboxProbes()
        );
    }

    /** The host would say sysbox-fs started after the container; the start times a sweep read once say otherwise and win. */
    public function test_start_times_a_sweep_hands_in_are_used_without_a_host_probe(): void
    {
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:05:00 UTC';
        $this->owners = ['0', '0'];
        $mgr = strtotime('2026-10-10 14:00:00 UTC');

        $this->assertFalse((new AccountMapping($this->project(), ['sysbox-mgr' => $mgr, 'sysbox-fs' => strtotime('2026-10-10 14:30:00 UTC')]))->isUnmapped());
        $this->assertTrue((new AccountMapping($this->project(), ['sysbox-mgr' => $mgr, 'sysbox-fs' => strtotime('2026-10-10 15:01:00 UTC')]))->isUnmapped());
        $this->assertSame([], $this->sysboxProbes());
    }

    /** A sweep whose probe could not answer hands every account unknown times: an exec refusal stays unmapped, a mapped account is left alone. */
    public function test_unknown_start_times_a_sweep_hands_in_are_not_probed_again(): void
    {
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED), '0'];
        $unknown = ['sysbox-mgr' => null, 'sysbox-fs' => null];

        $this->assertTrue((new AccountMapping($this->project(), $unknown))->isUnmapped());
        $this->assertFalse((new AccountMapping($this->project(), $unknown))->isUnmapped());
        $this->assertSame([], $this->sysboxProbes());
    }

    /** The caller listed the container as started at 15:00, before sysbox-fs; inspected on its own it is a container started since. */
    public function test_a_start_the_caller_listed_answers_that_check_only(): void
    {
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:05:00 UTC';
        $this->started['container'] = '2026-10-10T15:10:00.5Z';
        $this->owners = ['0', '0'];
        $mapping = new AccountMapping($this->project());

        $this->assertTrue($mapping->isUnmapped('2026-10-10T15:00:00.123456789Z'));
        $this->assertSame([], $this->startInspects());
        $this->assertFalse($mapping->isUnmapped(), 'a later check inspects the container, which may be new');
        $this->assertCount(1, $this->startInspects());
    }

    /** The container's start loses its fraction of a second, so one in the same second as sysbox-fs's is not before it. */
    public function test_a_container_started_since_sysbox_fs_or_in_its_second_is_left_alone(): void
    {
        foreach (['Sat 2026-10-10 14:59:59 UTC', 'Sat 2026-10-10 15:00:00 UTC'] as $since) {
            $this->started['sysbox-fs'] = $since;
            $this->owners = ['0'];

            $this->assertFalse((new AccountMapping($this->project()))->ensureMapped(), "sysbox-fs started {$since}");
        }
        $this->assertSame([], $this->recreates());
    }

    public function test_a_sysbox_fs_start_counts_only_while_both_daemons_run(): void
    {
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:05:00 UTC';
        foreach ([['failed', 'active'], ['activating', 'active'], ['active', 'inactive']] as [$fs, $mgr]) {
            $this->states = ['sysbox-fs' => $fs, 'sysbox-mgr' => $mgr];
            $this->owners = ['0'];

            $this->assertFalse((new AccountMapping($this->project()))->ensureMapped(), "sysbox-fs {$fs}, sysbox-mgr {$mgr}");
        }
        $this->assertSame([], $this->recreates());
    }

    public function test_a_mapped_account_whose_start_times_cannot_be_read_is_left_alone(): void
    {
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:05:00 UTC';
        $this->hostError = new \RuntimeException('The command could not be started.');
        $this->owners = ['0'];
        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped(), 'the host probe threw');

        $this->hostError = null;
        $this->started['container'] = '';
        $this->owners = ['0'];
        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped(), 'the container could not be inspected');

        $this->assertSame([], $this->recreates());
    }

    public function test_an_account_refusing_exec_started_after_sysbox_mgr_but_before_sysbox_fs_is_unmapped(): void
    {
        $this->started['container'] = '2026-10-10T15:10:00.5Z';
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:12:00 UTC';
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED)];

        $this->assertTrue((new AccountMapping($this->project()))->isUnmapped());
    }

    public function test_a_recreated_account_that_still_refuses_exec_is_a_failed_repair(): void
    {
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED), new \RuntimeException(self::EXEC_REFUSED)];
        $this->startedWhenReady = '2026-10-10T15:20:00Z';
        $project = $this->project();

        try {
            (new AccountMapping($project))->recreate();
            $this->fail('a recreate after which the account cannot be entered must not read as a repair');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after it was recreated', $e->getMessage());
        }
        $this->assertNull((new AccountMapping($project))->isUnmapped(), 'and the next sweep leaves it alone');
    }

    public function test_a_mapped_account_is_left_as_it_is(): void
    {
        $this->owners = ['0'];

        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());
        $this->assertSame([], $this->recreates());
        $this->assertSame(0, $this->awaited);
    }

    public function test_an_unmapped_account_is_recreated_awaited_and_asked_again(): void
    {
        Log::spy();
        $this->owners = ['65534', '65534', '0'];

        $this->assertTrue((new AccountMapping($this->project()))->ensureMapped());

        $this->assertSame(
            [['docker', 'compose', '-f', self::COMPOSE, 'up', '-d', '--remove-orphans', '--force-recreate']],
            $this->recreates()
        );
        $this->assertSame(1, $this->awaited);
        $this->assertCount(3, $this->probes());
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'alice: Recreated the account container: '
                . '/root inside it was owned by uid 65534 instead of root; sysbox was restarted while it ran'
        );
    }

    public function test_an_unmapped_account_started_since_sysbox_does_not_blame_a_sysbox_restart(): void
    {
        Log::spy();
        $this->started['container'] = '2026-10-10T15:10:00.5Z';
        $this->owners = ['1000', '1000', '0'];

        $this->assertTrue((new AccountMapping($this->project()))->ensureMapped());

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'alice: Recreated the account container: '
                . '/root inside it was owned by uid 1000 instead of root'
        );
    }

    public function test_unknown_start_times_do_not_blame_a_sysbox_restart(): void
    {
        Log::spy();
        $this->started = ['container' => '', 'sysbox' => '', 'sysbox-fs' => ''];
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED), new \RuntimeException(self::EXEC_REFUSED), '0'];

        $this->assertTrue((new AccountMapping($this->project()))->ensureMapped());

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'alice: Recreated the account container: '
                . 'exec into it was refused (unsafe procfs detected)'
        );
    }

    /** What a sweep prints for the account: the last check's finding, the same words the recreate logs. */
    public function test_the_reason_is_what_the_last_check_saw(): void
    {
        $this->started['sysbox-fs'] = 'Sat 2026-10-10 15:05:00 UTC';
        $this->owners = ['0', '1000', new \RuntimeException(self::EXEC_REFUSED)];
        $mapping = new AccountMapping($this->project());

        $this->assertSame('its image was unmapped', $mapping->reason(), 'before any check');
        $mapping->isUnmapped();
        $this->assertSame('sysbox-fs no longer serves its mounts', $mapping->reason());
        $mapping->isUnmapped();
        $this->assertSame('/root inside it was owned by uid 1000 instead of root', $mapping->reason());
        $mapping->isUnmapped();
        $this->assertSame('exec into it was refused (unsafe procfs detected)', $mapping->reason());
        $this->assertSame('its start was refused with permission denied on its own image', $mapping->afterRefusedStart()->reason());
    }

    public function test_a_refused_start_says_so(): void
    {
        Log::spy();
        $this->started['container'] = '2026-10-10T15:10:00.5Z';
        $this->upError = new \RuntimeException(self::START_REFUSED);
        $this->owners = ['0'];

        (new OuterLifecycle($this->project()))->up();

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'alice: Recreated the account container: '
                . 'its start was refused with permission denied on its own image'
        );
    }

    public function test_an_account_mapped_while_the_lock_was_waited_for_is_left_alone(): void
    {
        $this->owners = ['65534', '0'];

        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());

        $this->assertSame([], $this->recreates());
        $this->assertCount(2, $this->probes(), 'asked again under the lock before recreating');
    }

    public function test_an_account_another_process_is_repairing_is_left_to_it(): void
    {
        $this->owners = ['65534'];
        $held = $this->holdLockAsAnotherProcess();

        try {
            $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertSame([], $this->recreates());
    }

    public function test_a_deploy_holding_the_account_lock_still_repairs_the_mapping(): void
    {
        $lock = DeployLock::acquireFor('alice');
        $this->owners = ['65534', '0'];

        try {
            $this->assertTrue((new AccountMapping($this->project()))->ensureMapped());
        } finally {
            $lock->release();
        }

        $this->assertCount(1, $this->recreates());
    }

    public function test_still_unmapped_after_recreating_is_an_error(): void
    {
        $this->owners = ['65534', '65534', '65534'];

        try {
            (new AccountMapping($this->project()))->ensureMapped();
            $this->fail('a recreate that did not map the image must not carry on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('still has its image unmapped', $e->getMessage());
        }
        $this->assertCount(1, $this->recreates());
    }

    /** The repair a checkout change runs first, on a running account: git's exec would be refused until it is recreated. */
    public function test_the_repair_before_a_checkout_change_recreates_a_running_account_that_refuses_exec(): void
    {
        $this->owners = [new \RuntimeException(self::EXEC_REFUSED), new \RuntimeException(self::EXEC_REFUSED), '0'];

        (new DindDeployMechanics($this->project()))->repairAccountMapping();

        $this->assertCount(1, $this->recreates());
        $this->assertSame(1, $this->awaited);
    }

    public function test_a_privileged_account_is_never_probed(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $this->owners = ['65534'];

        $this->assertFalse((new AccountMapping($this->project()))->ensureMapped());
        $this->assertSame([], $this->probes());
    }

    public function test_up_brings_the_account_up_then_heals_it(): void
    {
        $this->owners = ['65534', '65534', '0'];

        (new OuterLifecycle($this->project()))->up();

        $ups = array_values(array_filter($this->commands, static fn (array $c): bool => in_array('up', $c, true)));
        $this->assertSame(['docker', 'compose', '-f', self::COMPOSE, 'up', '-d', '--remove-orphans'], $ups[0]);
        $this->assertContains('--force-recreate', $ups[1]);
    }

    public function test_up_recreates_a_container_whose_start_sysbox_refused(): void
    {
        $this->upError = new \RuntimeException(self::START_REFUSED);
        $this->owners = ['0'];

        (new OuterLifecycle($this->project()))->up();

        $this->assertSame(
            [['docker', 'compose', '-f', self::COMPOSE, 'up', '-d', '--remove-orphans', '--force-recreate']],
            $this->recreates()
        );
        $this->assertSame(1, $this->awaited);
        $this->assertCount(1, $this->probes(), 'asked once, after the new container is up');
    }

    public function test_up_fails_as_before_on_any_other_start_error(): void
    {
        $this->upError = new \RuntimeException('Error response from daemon: OCI runtime create failed: bind mount through procfd: not a directory');

        $this->expectExceptionMessage('not a directory');
        try {
            (new OuterLifecycle($this->project()))->up();
        } finally {
            $this->assertSame([], $this->recreates());
        }
    }

    public function test_a_privileged_account_whose_start_fails_is_not_recreated(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $this->upError = new \RuntimeException(self::START_REFUSED);

        $this->expectExceptionMessage('permission denied');
        try {
            (new OuterLifecycle($this->project()))->up();
        } finally {
            $this->assertSame([], $this->recreates());
        }
    }

    public function test_only_a_permission_refusal_from_the_runtime_is_a_start_refusal(): void
    {
        $this->assertTrue(AccountMapping::isStartRefusal(self::START_REFUSED));
        $this->assertTrue(AccountMapping::isStartRefusal('OCI runtime create failed: setting up rootfs mounts caused: lstat .//etc/docker/daemon.json: permission denied'));
        $this->assertFalse(AccountMapping::isStartRefusal(''));
        $this->assertFalse(AccountMapping::isStartRefusal('open /var/run/docker.sock: permission denied'));
        $this->assertFalse(AccountMapping::isStartRefusal('OCI runtime create failed: bind mount through procfd of /x -> etc/docker/daemon.json: not a directory'));
    }

    /** What `systemctl show` prints for both daemons, as the host probe asks; '' when it has no start time to give. */
    private function systemctlShow(): string
    {
        if ($this->hostError !== null) {
            throw $this->hostError;
        }
        if ($this->started['sysbox'] === '' && $this->started['sysbox-fs'] === '') {
            return '';
        }
        $since = ['sysbox-fs' => $this->started['sysbox-fs'], 'sysbox-mgr' => $this->started['sysbox']];

        return implode("\n\n", array_map(
            fn (string $daemon): string => "Id={$daemon}.service\nLoadState=loaded\nActiveState={$this->states[$daemon]}\nActiveEnterTimestamp={$since[$daemon]}",
            array_keys($since),
        ));
    }

    /** @return list<list<string>> */
    private function startInspects(): array
    {
        return array_values(array_filter($this->commands, static fn (array $c): bool => ($c[0] ?? '') === 'docker.startedAt'));
    }

    /** @return list<list<string>> */
    private function sysboxProbes(): array
    {
        return array_values(array_filter($this->commands, static fn (array $c): bool => in_array('systemctl', $c, true)));
    }

    /** @return resource */
    private function holdLockAsAnotherProcess()
    {
        $directory = $this->storage . '/logs/deploy/alice';
        mkdir($directory, 0777, true);
        $held = fopen($directory . '/.deploy.lock', 'c');
        flock($held, LOCK_EX | LOCK_NB);

        return $held;
    }

    /** @return list<list<string>> */
    private function recreates(): array
    {
        return array_values(array_filter($this->commands, static fn (array $c): bool => in_array('--force-recreate', $c, true)));
    }

    /** @return list<list<string>> */
    private function probes(): array
    {
        return array_values(array_filter($this->commands, static fn (array $c): bool => array_slice($c, -2) === ['%u', '/root']));
    }

    private function project(): Dind
    {
        $system = new class ($this->commands, $this->owners, $this->upError, $this->started, $this->systemctlShow(...)) extends System {
            /**
             * @param list<list<string>> $commands
             * @param list<string|\Throwable> $owners
             * @param array{container: string, sysbox: string, sysbox-fs: string} $started
             */
            public function __construct(private array &$commands, private array &$owners, private ?\Throwable &$upError, private array &$started, private \Closure $systemctlShow)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $request = array_values((array) $cmd);
                $argv = LocalRoot::plain($request);
                $this->commands[] = LocalRoot::action($request) === 'docker.startedAt' ? ['docker.startedAt', ...LocalRoot::values($request)] : $argv;
                $out = in_array('systemctl', $argv, true) ? ($this->systemctlShow)() : $this->started['container'];

                return new class ($out) extends Process {
                    public function __construct(private string $out)
                    {
                        parent::__construct(['true']);
                    }

                    public function isSuccessful(): bool
                    {
                        return $this->out !== '';
                    }

                    public function getOutput(): string
                    {
                        return $this->out . "\n";
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = LocalRoot::plain(array_values((array) $cmd));
                $this->commands[] = $argv;
                if (array_slice($argv, -2) === ['%u', '/root']) {
                    $owner = array_shift($this->owners) ?? '';
                    if ($owner instanceof \Throwable) {
                        throw $owner;
                    }

                    return $owner;
                }
                if (array_slice($argv, -3) === ['up', '-d', '--remove-orphans'] && $this->upError !== null) {
                    throw $this->upError;
                }

                return '';
            }
        };

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('composeFilePath')->willReturn(self::COMPOSE);
        $project->method('shell')->willReturn(new ShellOperations($project));
        $project->method('awaitReady')->willReturnCallback(function (): void {
            $this->awaited++;
            $this->started['container'] = $this->startedWhenReady ?? $this->started['container'];
        });

        return $project;
    }
}
