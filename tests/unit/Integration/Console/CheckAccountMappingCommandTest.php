<?php

namespace Tests\Integration\Console;

use App\Console\Kernel;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Models\User;
use App\System;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * `project:mapping:check` over a host where alice runs unmapped, bob mapped,
 * carol privileged and dave is stopped; of the stopped ones erin failed to
 * start on an unmapped image, frank was stopped by hand, gina is privileged
 * and hank failed for another reason.
 */
class CheckAccountMappingCommandTest extends TestCase
{
    /** What `systemctl show` prints after sysbox-fs restarted at 15:05 under a sysbox-mgr running since 14:00. */
    private const SYSBOX_FS_RESTARTED = "Id=sysbox-fs.service\nLoadState=loaded\nActiveState=active\nActiveEnterTimestamp=Sat 2026-10-10 15:05:00 UTC\n\n"
        . "Id=sysbox-mgr.service\nLoadState=loaded\nActiveState=active\nActiveEnterTimestamp=Sat 2026-10-10 14:00:00 UTC\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-mapping-check-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/logs/deploy', 0777, true);
        $this->app->useStoragePath($this->root . '/storage');
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->root . '/engine', 'homes' => $this->root . '/home']));
        config([
            'env.DIND_RUNTIME' => 'sysbox-runc',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach (['2014_10_12_000000_create_users_table.php', '2025_09_29_121922_add_status_to_users_table.php'] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }
        foreach (['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'gina', 'hank'] as $name) {
            User::create(['username' => $name, 'domain' => $name . '.example.test', 'details' => ['template' => 'dind']]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        putenv('PA_ROOT_LOCAL_ROOTS');
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_only_the_unmapped_sysbox_accounts_are_recreated(): void
    {
        $host = $this->host();

        $exit = Artisan::call('project:mapping:check');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(['alice', 'erin'], $host->recreated(), 'frank stopped by hand, gina privileged, hank failed otherwise');
        $this->assertSame(['alice', 'alice', 'alice', 'bob', 'erin'], $host->probed(), 'carol is privileged, dave stopped, ghost no project');
        $this->assertStringContainsString('alice: /root inside it was owned by uid 65534 instead of root, recreated the account container', $output);
        $this->assertStringContainsString('erin: its start was refused with permission denied on its own image, recreated the account container', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 to recreate, 1 stopped by a failed start, 2 recreated.', $output);
        $this->assertDirectoryDoesNotExist($this->root . '/storage/logs/deploy/alice', 'the lock directory it made is gone');
        $this->assertDirectoryDoesNotExist($this->root . '/storage/logs/deploy/erin');
    }

    public function test_dry_run_only_reports(): void
    {
        $host = $this->host();

        Artisan::call('project:mapping:check', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame([], $host->recreated());
        $this->assertStringContainsString('alice: /root inside it was owned by uid 65534 instead of root, would recreate the account container', $output);
        $this->assertStringContainsString('erin: its start was refused with permission denied on its own image, would recreate the account container', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 to recreate, 1 stopped by a failed start, 0 recreated.', $output);
    }

    public function test_an_account_a_deploy_holds_is_left_to_that_deploy(): void
    {
        $host = $this->host();
        $lock = DeployLock::acquireFor('alice');

        try {
            Artisan::call('project:mapping:check');
        } finally {
            $lock->release();
        }

        $this->assertSame(['erin'], $host->recreated());
        $this->assertStringContainsString('alice: /root inside it was owned by uid 65534 instead of root, left to the deploy or sweep holding its deploy lock', Artisan::output());
    }

    /** sysbox-fs restarted at 15:05: bob's container is older and every mount in it fails, alice's started since. */
    public function test_an_account_started_before_sysbox_fs_last_started_is_recreated_once(): void
    {
        $host = $this->host();
        $host->sysbox = self::SYSBOX_FS_RESTARTED;
        $host->started = ['alice' => '2026-10-10T15:10:00.5Z', 'bob' => '2026-10-10T15:00:00.5Z'];
        $host->owners['alice'] = ['0', '0'];
        $host->owners['bob'] = ['0', '0', '0', '0'];
        $host->owners['erin'] = ['0', '0'];

        Artisan::call('project:mapping:check');
        $first = Artisan::output();
        Artisan::call('project:mapping:check');
        $second = Artisan::output();

        $this->assertSame(['bob'], array_values(array_diff($host->recreated(), ['erin'])), 'alice started after sysbox-fs, bob before it until recreated');
        $this->assertStringContainsString('bob: sysbox-fs no longer serves its mounts, recreated the account container', $first);
        $this->assertStringContainsString('Checked 2 running account(s): 1 to recreate, 1 stopped by a failed start, 2 recreated.', $first);
        $this->assertStringContainsString('Checked 2 running account(s): 0 to recreate, 1 stopped by a failed start, 1 recreated.', $second);
    }

    /** bob's image is mapped; what the dry run names is sysbox-fs having started after his container. */
    public function test_dry_run_names_sysbox_fs_for_an_account_started_before_it(): void
    {
        $host = $this->host();
        $host->sysbox = self::SYSBOX_FS_RESTARTED;
        $host->started = ['alice' => '2026-10-10T15:10:00.5Z', 'bob' => '2026-10-10T15:00:00.5Z'];
        $host->owners['alice'] = ['0'];

        Artisan::call('project:mapping:check', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame([], $host->recreated());
        $this->assertStringContainsString('bob: sysbox-fs no longer serves its mounts, would recreate the account container', $output);
        $this->assertStringNotContainsString('image unmapped', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 to recreate, 1 stopped by a failed start, 0 recreated.', $output);
    }

    /** sysbox's start times are the same for every account of a sweep, recreated ones' second looks included: one host probe a sweep. */
    public function test_each_sweep_probes_sysbox_once_for_all_its_accounts(): void
    {
        $host = $this->host();
        $host->sysbox = self::SYSBOX_FS_RESTARTED;
        $host->started = ['alice' => '2026-10-10T15:00:00.5Z', 'bob' => '2026-10-10T15:10:00.5Z', 'erin' => '2026-10-10T15:00:00.5Z'];
        $host->owners['bob'] = ['0', '0'];
        $host->owners['erin'] = ['0', '0'];

        Artisan::call('project:mapping:check');
        $this->assertSame(['alice', 'erin'], $host->recreated());
        $this->assertCount(1, $host->sysboxProbes());

        Artisan::call('project:mapping:check');
        $this->assertCount(2, $host->sysboxProbes(), 'the next sweep reads sysbox again');
    }

    /** With no start times every account is checked as when its own probe failed: an exec refusal is recreated, a mapped account left alone. */
    public function test_a_sweep_whose_sysbox_probe_failed_checks_every_account_without_the_times(): void
    {
        $host = $this->host();
        $host->sysboxAnswers = false;
        $host->started = ['alice' => '2026-10-10T15:00:00.5Z', 'bob' => '2026-10-10T15:00:00.5Z'];
        $refused = new \RuntimeException('OCI runtime exec failed: exec failed: unable to start container process: unsafe procfs detected');
        $host->owners['alice'] = [$refused, $refused, '0'];

        Artisan::call('project:mapping:check');
        $output = Artisan::output();

        $this->assertSame(['alice', 'erin'], $host->recreated());
        $this->assertCount(1, $host->sysboxProbes());
        $this->assertStringContainsString('alice: exec into it was refused (unsafe procfs detected), recreated the account container', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 to recreate, 1 stopped by a failed start, 2 recreated.', $output);
    }

    /** The listing of running accounts already read when each started; only erin, stopped and recreated, is inspected on her own. */
    public function test_a_sweep_checks_running_accounts_with_the_start_their_listing_read(): void
    {
        $host = $this->host();
        $host->sysbox = self::SYSBOX_FS_RESTARTED;
        $host->started = ['alice' => '2026-10-10T15:10:00.5Z', 'bob' => '2026-10-10T15:20:00.5Z', 'erin' => '2026-10-10T15:00:00.5Z'];
        $host->owners['alice'] = ['0'];

        Artisan::call('project:mapping:check');

        $this->assertSame(['erin'], $host->recreated());
        $this->assertSame(['erin', 'erin'], $host->startInspected(), 'before and after erin is recreated');
    }

    /** alice's listed start is before sysbox-fs's, but a deploy recreated her container before the sweep took the lock. */
    public function test_a_container_recreated_by_another_caller_is_not_judged_by_its_listed_start(): void
    {
        $host = $this->host();
        $host->sysbox = self::SYSBOX_FS_RESTARTED;
        $host->started = ['alice' => '2026-10-10T15:00:00.5Z', 'bob' => '2026-10-10T15:20:00.5Z'];
        $host->recreatedByAnother = ['alice' => '2026-10-10T15:30:00.5Z'];
        $host->owners['alice'] = ['0', '0'];

        Artisan::call('project:mapping:check');

        $this->assertSame(['erin'], $host->recreated());
        $this->assertStringContainsString('alice: sysbox-fs no longer serves its mounts, repaired before the sweep reached it', Artisan::output());
    }

    /** alice was unmapped at first sight but cannot be asked under the lock, e.g. down or booting: nothing is recreated or called repaired. */
    public function test_an_account_the_second_check_cannot_reach_is_left_to_the_next_sweep(): void
    {
        $host = $this->host();
        $host->owners['alice'] = ['65534', ''];

        Artisan::call('project:mapping:check');
        $output = Artisan::output();

        $this->assertSame(['erin'], $host->recreated());
        $this->assertStringContainsString(
            'alice: /root inside it was owned by uid 65534 instead of root, could not be checked again, left to the next sweep',
            $output
        );
        $this->assertStringNotContainsString('repaired', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 to recreate, 1 stopped by a failed start, 1 recreated.', $output);
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $schedule = new Schedule();
        (new ReflectionMethod(Kernel::class, 'schedule'))->invoke(app(Kernel::class), $schedule);

        $event = collect($schedule->events())->first(
            static fn ($item): bool => str_contains((string) $item->command, 'project:mapping:check')
        );

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function host(): System
    {
        $host = new class ($this->root) extends System {
            /** @var list<list<string>> */
            public array $calls = [];

            /** @var array<string, list<string|\Throwable>> */
            public array $owners = ['alice' => ['65534', '65534', '0'], 'bob' => ['0'], 'carol' => ['65534'], 'dave' => ['65534'], 'erin' => ['0']];

            /** @var array<string, string> when each account container started, as docker inspect prints it */
            public array $started = [];

            /** what `systemctl show` prints for sysbox-fs and sysbox-mgr */
            public string $sysbox = '';

            /** false when the host probe of sysbox fails */
            public bool $sysboxAnswers = true;

            /** @var array<string, string> accounts another caller recreates right after the sweep first asks them, with the new start */
            public array $recreatedByAnother = [];

            /** @var list<string> */
            private array $startsInspected = [];

            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = LocalRoot::plain(array_values((array) $cmd));
                $this->calls[] = $argv;
                if (array_slice($argv, -2) === ['%u', '/root']) {
                    $account = $this->account($argv);
                    $owner = array_shift($this->owners[$account]) ?? '';
                    if (isset($this->recreatedByAnother[$account])) {
                        $this->started[$account] = $this->recreatedByAnother[$account];
                        unset($this->recreatedByAnother[$account]);
                    }
                    if ($owner instanceof \Throwable) {
                        throw $owner;
                    }

                    return $owner;
                }
                if (in_array('--force-recreate', $argv, true)) {
                    $this->started[$this->account($argv)] = '2026-10-10T15:30:00.5Z';
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = LocalRoot::plain(array_values((array) $cmd));
                $action = LocalRoot::action(array_values((array) $cmd));
                $values = LocalRoot::values(array_values((array) $cmd));
                $this->calls[] = $argv;
                $output = '';
                if ($action === 'docker.startedAt') {
                    $this->startsInspected[] = $values[0] ?? '';
                    $output = $this->startOf($values[0] ?? '');
                } elseif (($argv[0] ?? '') === 'systemctl') {
                    $output = $this->sysbox;
                } elseif ($action === 'docker.runningContainerIdsLabelled') {
                    $output = "a1\nb2\nc3\ng4\n";
                } elseif ($action === 'docker.stoppedContainerIdsLabelled') {
                    $output = "e5\nf6\ng7\nh8\n";
                } elseif ($action === 'docker.inspect' && in_array('e5', $values, true)) {
                    // What a start of a container sysbox forgot leaves in State.Error.
                    $refused = json_encode('failed to create task for container: failed to create shim task: OCI runtime create failed: '
                        . 'container_linux.go:439: starting container process caused: process_linux.go:608: container init caused: '
                        . 'rootfs_linux.go:76: setting up rootfs mounts caused: open etc/docker/daemon.json: permission denied');
                    $other = json_encode('failed to create task for container: failed to create shim task: OCI runtime create failed: '
                        . 'bind mount through procfd of /home/hank/daemon.json -> etc/docker/daemon.json: not a directory');
                    $output = "/erin\tsysbox-runc\t{$this->projectDirPath('erin')}\t{$this->startOf('erin')}\t{$refused}\n"
                        . "/frank\tsysbox-runc\t{$this->projectDirPath('frank')}\t{$this->startOf('frank')}\t\"\"\n"
                        . "/gina\trunc\t{$this->projectDirPath('gina')}\t{$this->startOf('gina')}\t{$refused}\n"
                        . "/hank\tsysbox-runc\t{$this->projectDirPath('hank')}\t{$this->startOf('hank')}\t{$other}\n";
                } elseif ($action === 'docker.inspect') {
                    $output = $this->listed(['alice' => 'sysbox-runc', 'bob' => 'sysbox-runc', 'carol' => 'runc', 'ghost' => 'sysbox-runc']);
                }
                $process = ($argv[0] ?? '') === 'systemctl' && ! $this->sysboxAnswers ? new Process(['false']) : new Process(['printf', '%s', $output]);
                $process->run();

                return $process;
            }

            /** @return list<string> */
            public function recreated(): array
            {
                $names = [];
                foreach ($this->calls as $argv) {
                    if (in_array('--force-recreate', $argv, true)) {
                        $names[] = $this->account($argv);
                    }
                }

                return $names;
            }

            /** @return list<string> the accounts whose container start was inspected on its own, in turn */
            public function startInspected(): array
            {
                return $this->startsInspected;
            }

            /**
             * The running containers' inspect lines: name, runtime, working directory, start and last start's error.
             *
             * @param  array<string, string>  $runtimes
             */
            private function listed(array $runtimes): string
            {
                $lines = '';
                foreach ($runtimes as $account => $runtime) {
                    $lines .= "/{$account}\t{$runtime}\t{$this->projectDirPath($account)}\t{$this->startOf($account)}\t\"\"\n";
                }

                return $lines;
            }

            private function startOf(string $account): string
            {
                return $this->started[$account] ?? '';
            }

            /** @return list<list<string>> */
            public function sysboxProbes(): array
            {
                return array_values(array_filter($this->calls, static fn (array $argv): bool => ($argv[0] ?? '') === 'systemctl'));
            }

            /** @return list<string> */
            public function probed(): array
            {
                $names = [];
                foreach ($this->calls as $argv) {
                    if (array_slice($argv, -2) === ['%u', '/root']) {
                        $names[] = $this->account($argv);
                    }
                }

                return $names;
            }

            /** @param list<string> $argv */
            private function account(array $argv): string
            {
                return LocalRoot::inAccount($argv) === [] ? basename(dirname((string) $argv[3])) : LocalRoot::values($argv)[0];
            }
        };
        $this->app->instance(System::class, $host);

        return $host;
    }
}
