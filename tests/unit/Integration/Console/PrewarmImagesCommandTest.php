<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\Dind\AccountImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\OperatorImage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * `system:image:prewarm` against a scripted root broker: what it plans, warms,
 * refreshes, removes, publishes and trims, and where it stops.
 */
class PrewarmImagesCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const GB = 1073741824;

    private string $dir;

    private string|false $pushLock;

    /** @var list<array<string, mixed>> */
    private array $builds;

    /** @var array<string, mixed> */
    private array $pull;

    private string $sibling;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-prewarm-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->pushLock = getenv('PA_PUSH_LOCK');
        putenv('PA_PUSH_LOCK=' . $this->dir . '/push.lock');
        file_put_contents($this->dir . '/pa-root', $this->brokerScript());
        chmod($this->dir . '/pa-root', 0755);
        LocalRoot::answerWith($this->dir . '/pa-root');

        $this->builds = array_values(array_filter(
            HostPrewarmPlan::available(['php']),
            static fn (array $item): bool => $item['kind'] === HostPrewarmPlan::KIND_BUILD
        ));
        $this->pull = array_values(array_filter(
            HostPrewarmPlan::available(),
            static fn (array $item): bool => $item['kind'] === 'pull'
        ))[0];
        $this->sibling = $this->builds[0]['ref'] . '-xdeadbeef';
        config(['deploy.prewarm_images' => '', 'deploy.prewarm_budget' => '', 'deploy.prewarm_reserve' => '']);
        $this->host([AccountImage::REF], free: 50 * self::GB);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        putenv($this->pushLock === false ? 'PA_PUSH_LOCK' : 'PA_PUSH_LOCK=' . $this->pushLock);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_an_unparseable_budget_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('system:image:prewarm', ['--budget' => 'lots']));

        $this->assertStringContainsString('Not a byte size: lots', Artisan::output());
        $this->assertSame([], $this->calls());
    }

    public function test_with_nothing_selected_it_only_keeps_the_account_image_and_publishes_nothing(): void
    {
        $this->answer('isRunning', ['out' => 'Cannot connect to the Docker daemon', 'code' => 1]);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--budget' => 'none', '--reserve' => '10G']));

        $output = Artisan::output();
        $this->assertStringContainsString('Free 50G, reserve 10G, budget none → spendable 40G', $output);
        $this->assertStringContainsString('No image is selected for prewarming', $output);
        $this->assertMatchesRegularExpression('/already present\s*\|\s*' . preg_quote(AccountImage::REF, '/') . '/', $output);
        $this->assertStringContainsString('Nothing to warm.', $output);
        $this->assertStringContainsString('panelalpha-cache-registry-writer is not running; nothing published.', $output);
        $this->assertCount(1, $this->called('pruneBuildCache'));
        $this->assertSame([], $this->called('registryGarbageCollect'));
    }

    public function test_a_dry_run_says_what_it_would_remove_and_touches_nothing(): void
    {
        $stale = $this->staleRef();
        $oldAccount = AccountImage::REPOSITORY . ':v0.0.1-20200101';
        $this->host([AccountImage::REF, $stale, $oldAccount, $this->sibling], free: 9 * self::GB);
        config(['deploy.prewarm_images' => $this->builds[0]['id']]);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--dry-run' => true, '--reserve' => '10G']));

        $output = Artisan::output();
        $this->assertStringContainsString("would remove superseded base image {$stale}", $output);
        $this->assertStringContainsString("would remove superseded account image {$oldAccount}", $output);
        $this->assertStringContainsString('giving up 1 rebuildable image(s)', $output);
        $this->assertStringContainsString('would have reclaimed base image ' . explode(':', $this->sibling, 2)[0], $output);
        $this->assertMatchesRegularExpression('/over budget\s*\|\s*' . preg_quote($this->builds[0]['ref'], '/') . '\s*\|\s*build\s*\|\s*\d+\s*\|\s*1\.5G/', $output);
        $this->assertSame([], $this->called('forceRemoveImages'));
        $this->assertSame([], $this->called('removeImages'));
        $this->assertSame([], $this->called('buildFromStdin'));
        $this->assertSame([], $this->called('pruneBuildCache'));
    }

    public function test_it_drops_superseded_images_and_reports_what_it_could_not(): void
    {
        $stale = $this->staleRef();
        $staleToo = $this->staleRef('20200202');
        $oldAccount = AccountImage::REPOSITORY . ':v0.0.1-20200101';
        $busyAccount = AccountImage::REPOSITORY . ':v0.0.2-20200101';
        $this->host([AccountImage::REF, $stale, $staleToo, $oldAccount, $busyAccount], free: 50 * self::GB);
        $this->answer("forceRemoveImages {$staleToo}", ['out' => 'image is in use', 'code' => 1]);
        $this->answer("removeImages {$busyAccount}", ['out' => 'conflict', 'code' => 1]);
        $this->answer('pruneBuildCache', ['out' => 'buildkit busy', 'code' => 1]);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--reserve' => '10G']));

        $output = Artisan::output();
        $this->assertStringContainsString("removed superseded base image {$stale}", $output);
        $this->assertStringContainsString("Could not remove {$staleToo}: image is in use", $output);
        $this->assertStringContainsString("removed superseded account image {$oldAccount}", $output);
        $this->assertStringContainsString("kept superseded account image {$busyAccount}: an account still runs on it", $output);
        $this->assertStringContainsString('Could not prune the build cache: buildkit busy', $output);
        $this->assertSame([[$stale], [$staleToo]], $this->values('forceRemoveImages'));
    }

    public function test_under_its_reserve_it_gives_up_rebuildable_images_of_known_size_and_never_an_upstream_one(): void
    {
        $busy = $this->builds[1]['ref'] . '-xfeedface';
        $unsized = $this->builds[2]['ref'] . '-xc0ffee00';
        $this->host([AccountImage::REF, $this->sibling, $busy, $unsized, $this->pull['ref']], free: 9 * self::GB);
        $this->answer('imageNamesWithSizes', ['out' => implode("\n", [
            AccountImage::REF . "\t1.5GB",
            "{$this->sibling}\t1.5GB",
            "{$busy}\t1.5GB",
            "{$unsized}\tN/A",
            "{$this->pull['ref']}\t1.5GB",
        ])]);
        $this->answer("forceRemoveImages {$busy}", ['out' => 'in use', 'code' => 1]);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--reserve' => '12G', '--keep-build-cache' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('Only 9G free against a 12G reserve; giving up 2 rebuildable image(s).', $output);
        $this->assertStringContainsString("reclaimed base image {$this->sibling}", $output);
        $this->assertStringContainsString("Could not remove {$busy}: in use", $output);
        $this->assertEqualsCanonicalizing([[$this->sibling], [$busy]], $this->values('forceRemoveImages'));
        $this->assertSame([], $this->called('pruneBuildCache'));
    }

    /** A size `docker images` would not give is never a reason to delete. */
    public function test_under_its_reserve_with_nothing_of_ours_of_known_size_it_says_so(): void
    {
        $this->host([AccountImage::REF, $this->sibling, $this->pull['ref']], free: 9 * self::GB);
        $this->answer('imageNamesWithSizes', ['out' => 'daemon unavailable', 'code' => 1]);

        Artisan::call('system:image:prewarm', ['--reserve' => '12G']);

        $this->assertStringContainsString('Only 9G free against a 12G reserve, and nothing of ours is safe to drop.', Artisan::output());
        $this->assertSame([], $this->called('forceRemoveImages'));
    }

    public function test_keep_unused_never_drops_a_current_image(): void
    {
        $this->host([AccountImage::REF, $this->sibling], free: 9 * self::GB);

        Artisan::call('system:image:prewarm', ['--reserve' => '12G', '--keep-unused' => true]);

        $this->assertStringNotContainsString('rebuildable', Artisan::output());
        $this->assertSame([], $this->called('forceRemoveImages'));
    }

    public function test_it_builds_the_selected_bases_publishes_them_and_trims_the_registry(): void
    {
        [$first, $second] = $this->builds;
        $this->host([AccountImage::REF, $this->sibling], free: 50 * self::GB);
        config(['deploy.prewarm_images' => "{$first['id']},{$second['id']}"]);
        $this->answer('diskFreeInKilobytes', [$this->df(50), $this->df(50), $this->df(50), $this->df(50), $this->df(50), $this->df(48)]);
        $this->answer('buildFromStdin', [['out' => 'no space left on device', 'code' => 1], ['out' => '']]);
        $this->answer('imageNames', [
            $this->listing([AccountImage::REF, $this->sibling]),
            $this->listing([AccountImage::REF, $this->sibling, $second['ref']]),
        ]);
        $repository = explode(':', $second['ref'], 2)[0];
        $this->registry([
            '/v2/_catalog?n=1000' => $this->jsonResponse(['repositories' => [$repository, 'gone/repo']]),
            "/v2/{$repository}/tags/list" => $this->jsonResponse(['tags' => [explode(':', $second['ref'], 2)[1], 'old']]),
            '/v2/gone/repo/tags/list' => $this->jsonResponse(['tags' => null]),
        ]);
        $this->manifest($second['ref'], 'a');
        $this->manifest("{$repository}:old", 'b');
        $this->answer('httpRequest DELETE', ['out' => "HTTP/1.1 202 Accepted\r\n\r\n"]);
        $this->answer('registryStorageKilobytes', [['out' => "2097152\t/var/lib/registry"], ['out' => "1048576\t/var/lib/registry"]]);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--budget' => 'none', '--reserve' => '10G']));

        $output = Artisan::output();
        $this->assertStringContainsString("→ build {$first['ref']}", $output);
        $this->assertStringContainsString('  failed, leaving it to the first deploy that needs it', $output);
        $this->assertStringContainsString("→ build {$second['ref']}", $output);
        $this->assertStringContainsString('2G of disk', $output);
        $this->assertStringContainsString('Warmed 1 image(s), 1 failed', $output);
        $this->assertStringContainsString('Published 1 image(s) to the cache registry', $output);
        $this->assertStringContainsString("removed from the cache registry: {$repository}:old", $output);
        $this->assertStringContainsString('Cache registry garbage-collected: 2G → 1G', $output);
        $this->assertCount(2, $this->called('buildFromStdin'));
        $this->assertSame([DindImageStore::HOST_CACHE_REGISTRY . '/' . $second['ref']], array_slice($this->values('pushQuietly')[0] ?? [], 0, 1));
        $this->assertCount(1, $this->called('registryGarbageCollect'));
        $this->assertSame([['gone/repo']], array_map(static fn (array $v): array => array_slice($v, -1), $this->values('removeRegistryRepository')));
    }

    public function test_it_stops_before_an_image_the_reserve_cannot_afford(): void
    {
        [$first, $second] = $this->builds;
        config(['deploy.prewarm_images' => "{$first['id']},{$second['id']}"]);
        $this->answer('diskFreeInKilobytes', [
            $this->df(50), $this->df(50), $this->df(50),
            $this->df(50), $this->df(9), $this->df(9),
        ]);

        Artisan::call('system:image:prewarm', ['--budget' => 'none', '--reserve' => '10G']);

        $output = Artisan::output();
        $this->assertStringContainsString('done in', $output);
        $this->assertStringContainsString('41G of disk', $output);
        $this->assertStringContainsString("Stopping before {$second['ref']}: 9G free against a 10G reserve", $output);
        $this->assertStringContainsString('Warmed 1 image(s)', $output);
        $this->assertCount(1, $this->called('buildFromStdin'));
    }

    public function test_it_stops_once_the_budget_is_spent(): void
    {
        [$first, $second] = $this->builds;
        config(['deploy.prewarm_images' => "{$first['id']},{$second['id']}"]);
        $this->answer('diskFreeInKilobytes', [
            $this->df(50), $this->df(50), $this->df(50),
            $this->df(50), $this->df(47), $this->df(47),
        ]);

        Artisan::call('system:image:prewarm', ['--budget' => '2G', '--reserve' => '10G']);

        $this->assertStringContainsString("Stopping before {$second['ref']}: spent 3G of a 2G budget", Artisan::output());
        $this->assertCount(1, $this->called('buildFromStdin'));
    }

    public function test_refresh_renews_present_images_one_at_a_time_and_drops_what_each_replaced(): void
    {
        [$first, $second] = $this->builds;
        $this->host([AccountImage::REF, $first['ref'], $second['ref'], $this->pull['ref']], free: 50 * self::GB);
        config(['deploy.prewarm_images' => "{$first['id']},{$second['id']},{$this->pull['id']}"]);
        $this->answer('imageId ' . AccountImage::REF, ['out' => 'No such image', 'code' => 1]);
        $this->answer('imageId ' . $first['ref'], [['out' => 'sha256:old'], ['out' => 'sha256:new']]);
        $this->answer('removeImages sha256:old', ['out' => 'in use by a container', 'code' => 1]);
        $this->answer('buildFromStdin', [['out' => ''], ['out' => 'network down', 'code' => 1]]);
        $this->answer('isRunning', ['out' => 'false']);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--refresh' => true, '--runtimes' => 'php,static,account']));

        $output = Artisan::output();
        $this->assertStringContainsString('↻ account ' . AccountImage::REF, $output);
        $this->assertStringContainsString("↻ build {$first['ref']}", $output);
        $this->assertStringContainsString('  updated in', $output);
        $this->assertStringContainsString('  unchanged in', $output);
        $this->assertStringContainsString('  refresh failed, keeping the current image', $output);
        $this->assertStringContainsString("↻ pull {$this->pull['ref']}", $output);
        $this->assertSame([['sha256:old']], $this->values('removeImages'));
        $this->assertSame([[$this->pull['ref']]], $this->values('pull'));
        $this->assertStringNotContainsString('operator', $output);
    }

    public function test_refresh_reports_every_operator_image_by_what_happened_to_it(): void
    {
        $this->answer('pull', ['out' => 'manifest unknown', 'code' => 1]);
        $this->answer('isRunning', ['out' => 'false']);
        OperatorImage::query()->create(['ref' => 'acme/busy:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_REMOVING]);
        OperatorImage::query()->create(['ref' => 'acme/gone:1', 'source' => 'local', 'status' => OperatorImage::STATUS_READY]);
        OperatorImage::query()->create(['ref' => 'acme/pulled:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--refresh' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('↷ operator acme/busy:1: being changed, skipped', $output);
        $this->assertStringContainsString('↻ operator local acme/gone:1', $output);
        $this->assertStringContainsString('  kept, not on the host in', $output);
        $this->assertStringContainsString('↻ operator pull acme/pulled:1', $output);
        $this->assertStringContainsString('  refresh failed, the cache registry keeps the current image: manifest unknown', $output);
        $this->assertSame([['acme/pulled:1']], $this->values('pull'));
    }

    public function test_a_failed_publish_and_an_unreadable_registry_leave_the_registry_alone(): void
    {
        $first = $this->builds[0];
        $this->host([AccountImage::REF, $first['ref']], free: 50 * self::GB);
        config(['deploy.prewarm_images' => $first['id']]);
        $this->answer('pushQuietly', ['out' => 'connection refused', 'code' => 1]);
        $this->registry(['/v2/_catalog?n=1000' => "HTTP/1.1 500 Internal Server Error\r\n\r\n"]);

        $this->assertSame(0, Artisan::call('system:image:prewarm'));

        $output = Artisan::output();
        $this->assertStringContainsString("Could not publish {$first['ref']}: connection refused", $output);
        $this->assertStringContainsString('Published 0 image(s) to the cache registry', $output);
        $this->assertStringContainsString('Could not list the cache registry; leaving it as it is.', $output);
        $this->assertSame([], $this->called('registryGarbageCollect'));
    }

    public function test_a_failed_garbage_collect_is_reported(): void
    {
        $this->registry(['/v2/_catalog?n=1000' => $this->jsonResponse(['repositories' => []])]);
        $this->answer('registryStorageKilobytes', ['out' => 'du: not found', 'code' => 1]);
        $this->answer('registryGarbageCollect', ['out' => 'storage locked', 'code' => 1]);

        $this->assertSame(0, Artisan::call('system:image:prewarm'));

        $this->assertStringContainsString('Cache registry garbage-collect failed: storage locked', Artisan::output());
    }

    public function test_an_unreadable_operator_image_table_leaves_the_registry_alone(): void
    {
        Schema::drop('operator_images');

        $this->assertSame(0, Artisan::call('system:image:prewarm', ['--refresh' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('Could not read the operator images; none refreshed.', $output);
        $this->assertStringContainsString('Could not read the operator images; leaving the cache registry as it is.', $output);
        $this->assertSame([], $this->called('registryGarbageCollect'));
    }

    public function test_the_old_name_still_answers(): void
    {
        $this->assertSame(0, Artisan::call('system:prewarm-images', ['--dry-run' => true]));

        $this->assertStringContainsString('spendable', Artisan::output());
    }

    /** @param list<string> $images */
    private function host(array $images, int $free): void
    {
        $this->writeState(['calls' => [], 'answers' => [
            'diskFreeInKilobytes' => $this->df(intdiv($free, self::GB)),
            'imageNames' => $this->listing($images),
            'imageNamesWithSizes' => ['out' => implode("\n", array_map(static fn (string $ref): string => "{$ref}\t1.5GB", $images))],
            'isRunning' => ['out' => 'true'],
            'inspectImage' => ['out' => 'No such image', 'code' => 1],
            'httpRequest' => ['out' => "HTTP/1.1 404 Not Found\r\n\r\n"],
        ]]);
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $answer */
    private function answer(string $key, array $answer): void
    {
        $state = $this->state();
        $state['answers'][$key] = $answer;
        $this->writeState($state);
    }

    /** @param array<string, string> $responses path => raw curl -i response */
    private function registry(array $responses): void
    {
        foreach ($responses as $path => $raw) {
            $this->answer('httpRequest GET http://' . DindImageStore::HOST_CACHE_REGISTRY . $path, ['out' => $raw]);
        }
    }

    private function manifest(string $ref, string $digest): void
    {
        [$repository, $tag] = explode(':', $ref, 2);
        $this->answer(
            'httpRequest HEAD http://' . DindImageStore::HOST_CACHE_REGISTRY . "/v2/{$repository}/manifests/{$tag}",
            ['out' => "HTTP/1.1 200 OK\r\nDocker-Content-Digest: sha256:" . str_repeat($digest, 64) . "\r\n\r\n"]
        );
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(array $body): string
    {
        return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n" . json_encode($body);
    }

    /** @return array{out: string} */
    private function df(int $freeGb): array
    {
        return ['out' => "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 104857600 1 " . ($freeGb * 1048576) . " 50% /var/lib/docker\n"];
    }

    /**
     * @param list<string> $images
     * @return array{out: string}
     */
    private function listing(array $images): array
    {
        return ['out' => implode("\n", [...$images, 'dangling:<none>'])];
    }

    private function staleRef(string $date = '20200101'): string
    {
        return (string) preg_replace('/-pa[0-9a-f]+$/', "-pa{$date}", $this->builds[0]['ref']);
    }

    /** @return list<list<string>> every request, action first */
    private function calls(): array
    {
        return $this->state()['calls'];
    }

    /** @return list<list<string>> */
    private function called(string $method): array
    {
        return array_values(array_filter(
            $this->calls(),
            static fn (array $call): bool => substr((string) $call[0], strrpos((string) $call[0], '.') + 1) === $method
        ));
    }

    /** @return list<list<string>> the values of each request to $method */
    private function values(string $method): array
    {
        return array_map(static fn (array $call): array => array_slice($call, 1), $this->called($method));
    }

    /** @return array{calls: list<list<string>>, answers: array<string, mixed>} */
    private function state(): array
    {
        return json_decode((string) file_get_contents($this->dir . '/state.json'), true);
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        file_put_contents($this->dir . '/state.json', json_encode($state));
    }

    /** A pa-root recording each request and answering from state.json by `method v0 v1`, `method v0`, `method`; a list answers in turn. */
    private function brokerScript(): string
    {
        return <<<'PHP'
            #!/usr/bin/env php
            <?php
            $args = array_slice($argv, 1);
            while ($args !== [] && $args[0] !== '--' && str_starts_with($args[0], '--')) {
                array_splice($args, 0, $args[0] === '--env' ? 2 : 1);
            }
            $name = (string) array_shift($args);
            if (($args[0] ?? null) === '--') {
                array_shift($args);
            }
            $method = substr($name, strrpos($name, '.') + 1);
            $handle = fopen(__DIR__ . '/state.json', 'c+');
            flock($handle, LOCK_EX);
            $state = json_decode(stream_get_contents($handle), true);
            $state['calls'][] = [$name, ...$args];
            $answer = ['out' => '', 'code' => 0];
            foreach ([implode(' ', [$method, ...array_slice($args, 0, 2)]), implode(' ', [$method, ...array_slice($args, 0, 1)]), $method] as $key) {
                if (! isset($state['answers'][$key])) {
                    continue;
                }
                $given = $state['answers'][$key];
                if (array_is_list($given)) {
                    $answer = $given[0];
                    if (count($given) > 1) {
                        array_shift($state['answers'][$key]);
                    }
                } else {
                    $answer = $given;
                }
                break;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state));
            flock($handle, LOCK_UN);
            fclose($handle);
            fwrite(($answer['code'] ?? 0) === 0 ? STDOUT : STDERR, (string) ($answer['out'] ?? ''));
            exit((int) ($answer['code'] ?? 0));
            PHP;
    }
}
