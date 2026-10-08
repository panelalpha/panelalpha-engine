<?php

namespace Tests\Unit\Console;

use App\Console\Commands\System\PruneHostImages;
use App\Console\Kernel;
use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\System;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `system:image:prune` against a host double and real deploy log files:
 * what it removes, what it keeps, and that it keeps out of the
 * way of a deploy in flight.
 */
class PruneHostImagesCommandTest extends TestCase
{
    private string $storage;

    private string $plain;

    private string $plainId;

    /** @var object{calls: list<list<string>>, images: array<string, int>, containers: list<string>} */
    private object $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/pa-image-prune-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs/deploy', 0777, true);
        mkdir($this->storage . '/app', 0777, true);
        $this->app->useStoragePath($this->storage);

        $this->plain = $this->plainPhpBase();
        // Prewarming is opt-in; select the plain base so it is the protected one.
        config(['deploy.prewarm_images' => $this->plainId]);
        $now = time();
        // tag => seconds since it was pulled or built on the host
        $this->host = $this->hostDouble([
            $this->plain => 400 * 86400,
            $this->plain . '-x0123abcd' => 10 * 86400,
            $this->plain . '-x89abcdef' => 10 * 86400,
            'golang:1.99-alpine' => 10 * 86400,
            'node:99-bookworm' => 3600,
            'ghcr.io/panelalpha/engine-core:20200101' => 400 * 86400,
            'registry:2' => 400 * 86400,
        ], ['registry:2'], $now);
        $this->app->instance(System::class, $this->host);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_removes_old_unused_images_and_keeps_everything_still_in_use(): void
    {
        $this->deployLog('alice', '20260901-000000-aaaaaa', "Pulled base image golang:1.99-alpine on the host and loaded it", 20 * 86400);
        $this->deployLog('alice', '20260920-000000-bbbbbb', "Loaded base image {$this->plain}-x89abcdef from host cache", 10 * 86400, latest: true);

        $exit = Artisan::call('system:image:prune');

        $this->assertSame(0, $exit, Artisan::output());
        $removed = $this->removed();
        // Old, outside the catalogue, named by no current deploy log.
        $this->assertContains($this->plain . '-x0123abcd', $removed);
        $this->assertContains('golang:1.99-alpine', $removed);
        // The prewarm catalogue, an account's latest deploy, a fresh pull, the
        // engine's own images and a container's image all stay.
        $this->assertNotContains($this->plain, $removed);
        $this->assertNotContains($this->plain . '-x89abcdef', $removed);
        $this->assertNotContains('node:99-bookworm', $removed);
        $this->assertNotContains('ghcr.io/panelalpha/engine-core:20200101', $removed);
        $this->assertNotContains('registry:2', $removed);
        $this->assertContains(
            ['sudo', 'docker', 'buildx', 'prune', '-af', '--filter', 'until=86400s'],
            $this->host->calls
        );
    }

    /** Not selected for prewarming, it is just another deploy image. */
    public function test_an_unselected_base_is_removed_once_unused(): void
    {
        config(['deploy.prewarm_images' => '']);

        Artisan::call('system:image:prune');

        $this->assertContains($this->plain, $this->removed());
    }

    public function test_a_recent_deploy_log_keeps_an_image_it_names(): void
    {
        $this->deployLog('bob', '20260923-000000-cccccc', 'FROM docker.io/library/golang:1.99-alpine', 3600);
        $this->deployLog('bob', '20260924-000000-dddddd', 'nothing', 60, latest: true);

        Artisan::call('system:image:prune');

        $this->assertNotContains('golang:1.99-alpine', $this->removed());
        $this->assertContains($this->plain . '-x0123abcd', $this->removed());
    }

    public function test_defers_images_but_not_build_cache_while_a_deploy_is_in_flight(): void
    {
        $this->deployLog('carol', '20260924-000000-eeeeee', 'Cloning repository', 60, latest: true);
        $lock = fopen($this->storage . '/logs/deploy/carol/.deploy.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $exit = Artisan::call('system:image:prune');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Deferred: deploy in flight for carol', Artisan::output());
        $this->assertSame([['sudo', 'docker', 'buildx', 'prune', '-af', '--filter', 'until=86400s']], $this->host->calls);
        $this->assertNull(PruneHostImages::lastCompleted());
    }

    public function test_due_after_skips_images_until_the_window_passes(): void
    {
        Artisan::call('system:image:prune', ['--due-after' => '20h']);
        $this->assertNotSame([], $this->removed());
        $this->assertEqualsWithDelta(time(), PruneHostImages::lastCompleted(), 5);

        $this->host->calls = [];
        Artisan::call('system:image:prune', ['--due-after' => '20h']);
        $this->assertStringContainsString('Image prune not due', Artisan::output());
        $this->assertSame([], $this->removed());
        // The build cache half is cheap and still runs every time.
        $this->assertCount(1, $this->host->calls);

        PruneHostImages::recordCompleted(time() - 21 * 3600);
        $this->host->calls = [];
        Artisan::call('system:image:prune', ['--due-after' => '20h']);
        $this->assertNotSame([], $this->removed());
    }

    public function test_a_dry_run_does_not_count_as_completed(): void
    {
        Artisan::call('system:image:prune', ['--dry-run' => true, '--due-after' => '20h']);

        $this->assertNull(PruneHostImages::lastCompleted());
    }

    public function test_dry_run_and_off_remove_nothing(): void
    {
        Artisan::call('system:image:prune', ['--dry-run' => true]);
        $this->assertStringContainsString('would remove', Artisan::output());
        $this->assertSame([], $this->removed());

        $this->host->calls = [];
        config(['deploy.host_image_retention' => 'off', 'deploy.host_build_cache_retention' => 'off']);
        Artisan::call('system:image:prune');
        $this->assertStringContainsString('Host image prune is off.', Artisan::output());
        $this->assertSame([], $this->host->calls);
    }

    public function test_it_is_scheduled_hourly_with_a_due_window_without_overlapping(): void
    {
        $schedule = new Schedule();
        (new ReflectionMethod(Kernel::class, 'schedule'))->invoke(app(Kernel::class), $schedule);

        $event = collect($schedule->events())->first(
            static fn ($item): bool => str_contains((string) $item->command, 'system:image:prune')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertStringContainsString('--due-after=20h', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @return list<string>
     */
    private function removed(): array
    {
        $removed = [];
        foreach ($this->host->calls as $argv) {
            if (array_slice($argv, 0, 3) === ['sudo', 'docker', 'rmi']) {
                $removed = array_merge($removed, array_slice($argv, 3));
            }
        }

        return $removed;
    }

    private function deployLog(string $user, string $id, string $message, int $age, bool $latest = false): void
    {
        $dir = $this->storage . '/logs/deploy/' . $user;
        @mkdir($dir, 0777, true);
        $path = "{$dir}/{$id}.log";
        file_put_contents($path, json_encode(['level' => 'info', 'message' => $message]) . "\n");
        touch($path, time() - $age);
        if ($latest) {
            file_put_contents("{$dir}/latest.json", (string) json_encode(['id' => $id, 'status' => 'success']));
        }
    }

    /**
     * @param array<string, int> $images tag => age in seconds
     * @param list<string> $containers
     */
    private function hostDouble(array $images, array $containers, int $now): System
    {
        return new class ($images, $containers, $now) extends System {
            /** @var list<list<string>> */
            public array $calls = [];

            /**
             * @param array<string, int> $images
             * @param list<string> $containers
             */
            public function __construct(public array $images, public array $containers, private int $now)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = array_values((array) $cmd);
                $this->calls[] = $argv;

                return match (implode(' ', array_slice($argv, 0, 4))) {
                    'sudo docker images --format' => implode("\n", array_map(
                        static fn (string $tag): string => "{$tag}\t1.1GB",
                        array_keys($this->images)
                    )),
                    'sudo docker ps -a' => implode("\n", $this->containers),
                    'sudo docker image inspect' => implode("\n", array_map(
                        fn (string $tag): string => json_encode(gmdate('Y-m-d\TH:i:s.123456789\Z', $this->now - $this->images[$tag])),
                        array_slice($argv, 7)
                    )),
                    default => '',
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('unexpected process');
            }
        };
    }

    private function plainPhpBase(): string
    {
        foreach (HostPrewarmPlan::available() as $item) {
            $ref = (string) $item['ref'];
            if (BuiltImage::runtimeFor($ref) === 'php' && !BuiltImage::isVariant($ref)) {
                $this->plainId = $item['id'];

                return $ref;
            }
        }
        $this->markTestSkipped('the catalogue warms no plain PHP base');
    }
}
