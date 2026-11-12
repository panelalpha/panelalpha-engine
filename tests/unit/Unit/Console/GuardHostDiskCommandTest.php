<?php

namespace Tests\Unit\Console;

use App\Console\Commands\System\GuardHostDisk;
use App\Console\Kernel;
use App\System;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\Support\HostCommand;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * `system:disk:guard` against a host double whose free space grows by what
 * each prune reports freeing.
 */
class GuardHostDiskCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const G = 1073741824;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->storage = sys_get_temp_dir() . '/pa-disk-guard-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs/deploy', 0777, true);
        mkdir($this->storage . '/app', 0777, true);
        $this->app->useStoragePath($this->storage);
        config(['deploy.host_build_cache_max' => '10G', 'deploy.disk_pressure_free' => '15%']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_a_healthy_host_only_gets_the_cache_cap(): void
    {
        $host = $this->host(free: 50 * self::G, frees: []);

        Artisan::call('system:disk:guard');

        $this->assertSame([[LocalRoot::CLIENT, 'docker.pruneBuildCacheAbove', '--', (string) (10 * self::G)]], $host->calls);
        $this->assertSame([(string) (10 * self::G)], LocalRoot::values($host->calls[0]));
        $this->assertStringContainsString('nothing to do', Artisan::output());
    }

    public function test_low_disk_prunes_build_cache_to_the_target_and_stops_there(): void
    {
        $host = $this->host(free: 5 * self::G, frees: ['docker.pruneBuildCacheUntilFree' => 12 * self::G]);

        Artisan::call('system:disk:guard');

        $this->assertContains(
            [LocalRoot::CLIENT, 'docker.pruneBuildCacheUntilFree', '--', (string) (15 * self::G)],
            $host->calls
        );
        $this->assertFalse($host->listedImages(), 'images are left alone once the cache freed enough');
        $this->assertSame(17 * self::G, $host->free);
    }

    /** With nothing to free, each age pass lists once: 6h, 3h, 1.5h, 45m, 22.5m and any age. */
    public function test_disk_still_low_after_the_cache_falls_back_to_images_and_says_so(): void
    {
        Log::spy();
        $host = $this->host(free: 5 * self::G, frees: []);

        Artisan::call('system:disk:guard');

        $this->assertTrue($host->listedImages());
        $this->assertSame(6, $host->imageListings());
        Log::shouldHaveReceived('log')->withArgs(
            static fn (string $level, string $message): bool
                => $level === 'error' && str_contains($message, 'held by images in use')
        );
    }

    public function test_dry_run_prunes_and_logs_nothing(): void
    {
        Log::spy();
        $host = $this->host(free: 5 * self::G, frees: []);

        Artisan::call('system:disk:guard', ['--dry-run' => true]);

        $this->assertSame([], $host->calls);
        $this->assertStringContainsString('Would prune host build cache down to', Artisan::output());
        Log::shouldNotHaveReceived('log');
    }

    public function test_off_skips_both_halves(): void
    {
        config(['deploy.host_build_cache_max' => 'off', 'deploy.disk_pressure_free' => 'off']);
        $host = $this->host(free: 1 * self::G, frees: []);

        Artisan::call('system:disk:guard');

        $this->assertSame([], $host->calls);
    }

    public function test_target_and_freed_parsing(): void
    {
        $this->assertSame(15 * self::G, GuardHostDisk::target('15%', 100 * self::G));
        $this->assertSame(15 * self::G, GuardHostDisk::target('', 100 * self::G));
        $this->assertSame(20 * self::G, GuardHostDisk::target('20G', 100 * self::G));
        $this->assertNull(GuardHostDisk::target('off', 100 * self::G));
        $this->assertNull(GuardHostDisk::target('0%', 100 * self::G));
        $this->assertSame(10 * self::G, GuardHostDisk::bytes('lots'));

        $this->assertSame(0, GuardHostDisk::freed("Total:\t0B"));
        $this->assertSame((int) round(6.4 * self::G), GuardHostDisk::freed("ID\tRECLAIMABLE\tSIZE\nabc\ttrue\t1GB\nTotal:\t6.4GB\n"));
        $this->assertSame(0, GuardHostDisk::freed('unknown flag: --max-used-space'));
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $schedule = new Schedule();
        (new ReflectionMethod(Kernel::class, 'schedule'))->invoke(app(Kernel::class), $schedule);

        $event = collect($schedule->events())->first(
            static fn ($item): bool => str_contains((string) $item->command, 'system:disk:guard')
        );

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * A 100G filesystem with $free bytes free on both deploy paths.
     *
     * @param array<string, int> $frees buildx limit flag => bytes that prune frees
     */
    private function host(int $free, array $frees): System
    {
        $host = new class ($free, $frees) extends System {
            /** @var list<list<string>> */
            public array $calls = [];

            /** @param array<string, int> $frees build cache prune action => bytes it frees */
            public function __construct(public int $free, private array $frees)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = array_values((array) $cmd);
                $this->calls[] = $argv;
                $action = (string) (LocalRoot::isRequest($argv) ? LocalRoot::action($argv) : '');
                if (str_starts_with($action, 'docker.pruneBuildCache')) {
                    $freed = $this->frees[$action] ?? 0;
                    $this->free += $freed;

                    return "Total:\t" . round($freed / 1073741824, 1) . 'GB';
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $path = is_array($cmd) ? (string) end($cmd) : '';
                $size = 100 * 1048576;

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . "/dev/sda1 {$size} 1 " . intdiv($this->free, 1024) . " 50% {$path}\n";
            }

            /** @return list<string> */
            public function actions(): array
            {
                return array_values(array_map(
                    static fn (array $argv): string => (string) LocalRoot::action($argv),
                    array_filter($this->calls, static fn (array $argv): bool => LocalRoot::isRequest($argv))
                ));
            }

            public function imageListings(): int
            {
                return count(array_filter(
                    $this->actions(),
                    static fn (string $action): bool => $action === 'docker.imageNamesWithSizes'
                ));
            }

            public function listedImages(): bool
            {
                return $this->imageListings() > 0;
            }
        };
        $this->app->instance(System::class, $host);

        return $host;
    }
}
