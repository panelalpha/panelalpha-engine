<?php

namespace Tests\Unit\Console;

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
use Tests\TestCase;

/**
 * `project:mapping:check` over a host where alice runs unmapped, bob mapped,
 * carol privileged and dave is stopped; of the stopped ones erin failed to
 * start on an unmapped image, frank was stopped by hand, gina is privileged
 * and hank failed for another reason.
 */
class CheckAccountMappingCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-mapping-check-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/logs/deploy', 0777, true);
        $this->app->useStoragePath($this->root . '/storage');
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
        $this->assertStringContainsString('alice: image unmapped, recreated the account container', $output);
        $this->assertStringContainsString('erin: start failed on an unmapped image, recreated the account container', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 unmapped, 1 stopped by a failed start, 2 recreated.', $output);
        $this->assertDirectoryDoesNotExist($this->root . '/storage/logs/deploy/alice', 'the lock directory it made is gone');
        $this->assertDirectoryDoesNotExist($this->root . '/storage/logs/deploy/erin');
    }

    public function test_dry_run_only_reports(): void
    {
        $host = $this->host();

        Artisan::call('project:mapping:check', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame([], $host->recreated());
        $this->assertStringContainsString('alice: image unmapped, would recreate the account container', $output);
        $this->assertStringContainsString('erin: start failed on an unmapped image, would recreate the account container', $output);
        $this->assertStringContainsString('Checked 2 running account(s): 1 unmapped, 1 stopped by a failed start, 0 recreated.', $output);
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
        $this->assertStringContainsString('left to the deploy or sweep holding its deploy lock', Artisan::output());
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

            /** @var array<string, list<string>> */
            private array $owners = ['alice' => ['65534', '65534', '0'], 'bob' => ['0'], 'carol' => ['65534'], 'dave' => ['65534'], 'erin' => ['0']];

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
                $argv = array_values((array) $cmd);
                $this->calls[] = $argv;
                if (array_slice($argv, -2) === ['%u', '/root']) {
                    return array_shift($this->owners[$this->account($argv)]) ?? '';
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = array_values((array) $cmd);
                $this->calls[] = $argv;
                $output = '';
                if (array_slice($argv, 0, 3) === ['sudo', 'docker', 'ps']) {
                    $output = in_array('-a', $argv, true) ? "e5\nf6\ng7\nh8\n" : "a1\nb2\nc3\ng4\n";
                } elseif (array_slice($argv, 0, 3) === ['sudo', 'docker', 'inspect'] && in_array('e5', $argv, true)) {
                    // What a start of a container sysbox forgot leaves in State.Error.
                    $refused = json_encode('failed to create task for container: failed to create shim task: OCI runtime create failed: '
                        . 'container_linux.go:439: starting container process caused: process_linux.go:608: container init caused: '
                        . 'rootfs_linux.go:76: setting up rootfs mounts caused: open etc/docker/daemon.json: permission denied');
                    $other = json_encode('failed to create task for container: failed to create shim task: OCI runtime create failed: '
                        . 'bind mount through procfd of /home/hank/daemon.json -> etc/docker/daemon.json: not a directory');
                    $output = "/erin\tsysbox-runc\t{$this->projectDirPath('erin')}\t{$refused}\n"
                        . "/frank\tsysbox-runc\t{$this->projectDirPath('frank')}\t\"\"\n"
                        . "/gina\trunc\t{$this->projectDirPath('gina')}\t{$refused}\n"
                        . "/hank\tsysbox-runc\t{$this->projectDirPath('hank')}\t{$other}\n";
                } elseif (array_slice($argv, 0, 3) === ['sudo', 'docker', 'inspect']) {
                    $output = "/alice\tsysbox-runc\t{$this->projectDirPath('alice')}\n"
                        . "/bob\tsysbox-runc\t{$this->projectDirPath('bob')}\n"
                        . "/carol\trunc\t{$this->projectDirPath('carol')}\n"
                        . "/ghost\tsysbox-runc\t{$this->projectDirPath('ghost')}\n";
                }
                $process = new Process(['printf', '%s', $output]);
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
                return basename(dirname((string) $argv[4]));
            }
        };
        $this->app->instance(System::class, $host);

        return $host;
    }
}
