<?php

namespace Tests\Unit\Console;

use App\Console\Commands\System\PruneHostImages;
use App\Console\Kernel;
use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Models\OperatorImage;
use App\System;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * `system:image:prune` against a host double and real deploy log files:
 * what it removes, what it keeps, and that it keeps out of the
 * way of a deploy in flight.
 */
class PruneHostImagesCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $storage;

    private string $plain;

    private string $plainId;

    /** @var object{calls: list<list<string>>, images: array<string, int>, containers: list<string>} */
    private object $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
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
            [LocalRoot::CLIENT, 'docker.pruneBuildCacheUnusedFor', '--', '86400'],
            $this->host->calls
        );
    }

    /** Not selected for prewarming, the plain base stays only as the newest of its kind. */
    public function test_an_unselected_base_stays_as_the_newest_of_its_kind_and_an_old_recipe_goes(): void
    {
        config(['deploy.prewarm_images' => '']);
        $old = (string) preg_replace('/-pa[0-9a-f]+$/', '-pa20200101', $this->plain);
        $this->host->images[$old] = 3600;

        Artisan::call('system:image:prune');

        $this->assertNotContains($this->plain, $this->removed());
        $this->assertContains($old, $this->removed());
    }

    /** 6h, then 3h: the 3h pass frees enough, so the 1.5h one never runs. */
    public function test_under_pressure_the_window_halves_until_enough_is_free(): void
    {
        $this->pressureHost(free: 13);

        Artisan::call('system:image:prune', ['--older-than' => '6h', '--until-free' => '15G', '--build-cache-older-than' => 'off']);

        $this->assertSame([$this->plain . '-x00000007', $this->plain . '-x00000004'], $this->removed());
        $this->assertStringContainsString('Images unused for 3h:', Artisan::output());
        $this->assertStringNotContainsString('Images unused for 1.5h:', Artisan::output());
    }

    /**
     * Held means by a container, the prewarm catalogue or an in-flight deploy, or the newest of a kind; nothing defers it.
     */
    public function test_the_last_pressure_pass_leaves_only_what_is_held(): void
    {
        $this->pressureHost(free: 0);
        $this->deployLog('carol', '20260924-000000-eeeeee', "Host build image: {$this->plain}-x00000004", 60, latest: true);
        $lock = fopen($this->storage . '/logs/deploy/carol/.deploy.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            Artisan::call('system:image:prune', ['--older-than' => '6h', '--until-free' => '50G', '--build-cache-older-than' => 'off']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $output = Artisan::output();
        $this->assertSame([$this->plain . '-x00000007', $this->plain . '-x00000002', $this->plain . '-x00000001'], $this->removed());
        $this->assertStringContainsString('Images nothing holds, of any age:', $output);
        $this->assertStringContainsString('every image left is in use or the newest of its kind', $output);
        $this->assertStringNotContainsString('Deferred', $output);
    }

    public function test_pressure_windows_halve_down_to_any_age(): void
    {
        $windows = [21600];
        while (end($windows) !== 0) {
            $windows[] = PruneHostImages::nextWindow(end($windows));
        }

        $this->assertSame([21600, 10800, 5400, 2700, 1350, 0], $windows);
    }

    /**
     * A tag sharing a kept tag's digest stays.
     */
    public function test_cache_registry_tags_follow_the_same_rule_and_are_garbage_collected(): void
    {
        $now = time();
        $stale = $this->plain . '-x0123abcd';
        $newest = $this->plain . '-x89abcdef';
        $sharing = $this->plain . '-xfeedface';
        $named = $this->plain . '-x00c0ffee';
        $this->host->registry = [
            $stale => ['a', $now - 10 * 86400],
            $newest => ['b', $now - 3600],
            $sharing => ['b', $now - 10 * 86400],
            $named => ['d', $now - 10 * 86400],
            $this->plain => ['c', $now - 400 * 86400],
        ];
        $this->deployLog('alice', '20260920-000000-bbbbbb', "Pulled base image {$named} from the cache registry", 10 * 86400, latest: true);
        $requests = [];
        $this->app->instance(CacheRegistry::class, new CacheRegistry(
            static function (string $method, string $url) use (&$requests): array {
                $requests[] = "{$method} {$url}";

                return ['status' => 202, 'headers' => [], 'body' => ''];
            },
            'http://registry.test'
        ));

        Artisan::call('system:image:prune');

        $repository = explode(':', $this->plain, 2)[0];
        $this->assertSame([
            "DELETE http://registry.test/v2/{$repository}/manifests/sha256:" . str_repeat('a', 64),
            'GET http://registry.test/v2/_catalog?n=1000',
        ], $requests);
        $gc = array_values(array_filter(
            $this->host->calls,
            static fn (array $argv): bool => LocalRoot::action($argv) === 'docker.registryGarbageCollect'
        ));
        $this->assertCount(1, $gc);
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
        $this->assertSame([[LocalRoot::CLIENT, 'docker.pruneBuildCacheUnusedFor', '--', '86400']], $this->host->calls);
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

    public function test_an_operator_image_is_kept_even_in_a_repository_the_catalogue_names(): void
    {
        OperatorImage::query()->create(['ref' => 'golang:1.99-alpine', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);

        $this->assertSame(0, Artisan::call('system:image:prune'), Artisan::output());

        $this->assertNotContains('golang:1.99-alpine', $this->removed());
        $this->assertContains($this->plain . '-x0123abcd', $this->removed());
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
            $action = LocalRoot::isRequest($argv) ? LocalRoot::action($argv) : null;
            if (in_array($action, ['docker.removeImages', 'docker.forceRemoveImages'], true)) {
                $removed = array_merge($removed, LocalRoot::values($argv));
            }
        }

        return $removed;
    }

    /**
     * Five variants of one PHP base, unused for 7h, 4h, 2h, 1h and 10 minutes,
     * on a 100G disk with $free G free; each removal frees 1G.
     */
    private function pressureHost(int $free): void
    {
        config(['deploy.prewarm_images' => '']);
        $this->host = $this->hostDouble([
            $this->plain . '-x00000007' => 7 * 3600,
            $this->plain . '-x00000004' => 4 * 3600,
            $this->plain . '-x00000002' => 2 * 3600,
            $this->plain . '-x00000001' => 3600,
            $this->plain . '-x0000000a' => 600,
        ], [], time());
        $this->host->free = $free * 1073741824;
        $this->host->forget = true;
        $this->app->instance(System::class, $this->host);
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

            /** @var array<string, array{0: string, 1: int}> registry ref => [digest letter, pushed at] */
            public array $registry = [];

            /** Bytes free on the 100G disk; a removal frees 1G. */
            public int $free = 0;

            /** Whether a removed image leaves the listing. */
            public bool $forget = false;

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
                $action = LocalRoot::isRequest($argv) ? LocalRoot::action($argv) : null;
                if (in_array($action, ['docker.removeImages', 'docker.forceRemoveImages'], true)) {
                    $this->free += 1073741824;
                    if ($this->forget) {
                        foreach (LocalRoot::values($argv) as $tag) {
                            unset($this->images[$tag]);
                        }
                    }
                }
                if ($action === 'docker.registryTagLinks') {
                    $lines = [];
                    foreach ($this->registry as $ref => [$digest, $pushed]) {
                        [$repository, $tag] = explode(':', $ref, 2);
                        $lines[] = "{$pushed} sha256:" . str_repeat($digest, 64)
                            . " /var/lib/registry/docker/registry/v2/repositories/{$repository}/_manifests/tags/{$tag}/current/link";
                    }

                    return implode("\n", $lines);
                }
                if ($action === 'docker.containerImages') {
                    return implode("\n", $this->containers);
                }

                return match ($action) {
                    'docker.imageNamesWithSizes' => implode("\n", array_map(
                        static fn (string $tag): string => "{$tag}\t1.1GB",
                        array_keys($this->images)
                    )),
                    'docker.imagesLastTagTime' => implode("\n", array_map(
                        fn (string $tag): string => json_encode(gmdate('Y-m-d\TH:i:s.123456789\Z', $this->now - $this->images[$tag])),
                        LocalRoot::values($argv)
                    )),
                    default => '',
                };
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $path = is_array($cmd) ? (string) end($cmd) : '';

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . '/dev/sda1 ' . (100 * 1048576) . ' 1 ' . intdiv($this->free, 1024) . " 50% {$path}\n";
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
