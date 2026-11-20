<?php

namespace Tests\Integration\Console;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Models\OperatorImage;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `system:image:*` with the host's docker and the cache registry answered by a scripted root broker. Plain HTTP
 * to a registry goes through a proxy on a closed loopback port, so nothing leaves the machine and a size reads as
 * unknown.
 */
class OperatorImageCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private const DOCKERFILE = "FROM debian:bookworm-slim\nRUN true\n";

    private const DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ScriptedRoot $root;

    private string $lock;

    private string|false $previousLock;

    private string|false $previousProxy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = new ScriptedRoot;
        $this->previousLock = getenv('PA_PUSH_LOCK');
        $this->lock = sys_get_temp_dir().'/pa-push-lock-'.bin2hex(random_bytes(4));
        putenv('PA_PUSH_LOCK='.$this->lock);
        $this->previousProxy = getenv('HTTP_PROXY');
        putenv('HTTP_PROXY=http://'.TestNetwork::LOOPBACK.':1');
    }

    protected function tearDown(): void
    {
        putenv($this->previousLock === false ? 'PA_PUSH_LOCK' : 'PA_PUSH_LOCK='.$this->previousLock);
        putenv($this->previousProxy === false ? 'HTTP_PROXY' : 'HTTP_PROXY='.$this->previousProxy);
        @unlink($this->lock);
        $this->root->close();
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function run_(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private function registryHasDigest(): void
    {
        $this->root->on('host.httpRequest', "HTTP/1.1 200 OK\r\nDocker-Content-Digest: ".self::DIGEST."\r\n\r\n", 0, 'HEAD http');
    }

    /** @param array<string, mixed> $attributes */
    private function image(array $attributes): OperatorImage
    {
        return OperatorImage::query()->create($attributes + ['status' => OperatorImage::STATUS_READY]);
    }

    public function test_adding_needs_exactly_one_source(): void
    {
        try {
            Artisan::call('system:image:add', ['ref' => 'mybase:1', '--pull' => true, '--local' => true]);
            $this->fail('two sources were accepted');
        } catch (ProblemException $e) {
            $this->assertSame('Pass exactly one of --pull, --build or --local.', $e->getMessage());
        }
        $this->assertSame(0, OperatorImage::query()->count());
    }

    public function test_a_pulled_image_is_published_and_recorded_with_its_digest(): void
    {
        $this->root->on('docker.pull', "Pulled debian:bookworm-slim\n");
        $this->registryHasDigest();

        [$code, $out] = $this->run_('system:image:add', ['ref' => 'debian:bookworm-slim', '--pull' => true, '--why' => 'base for tools']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Pulled debian:bookworm-slim', $out);
        $this->assertStringContainsString('debian:bookworm-slim is in the cache registry as '.self::DIGEST.'. Projects on this host can build FROM debian:bookworm-slim.', $out);
        $image = OperatorImage::findByRef('debian:bookworm-slim');
        $this->assertSame([OperatorImage::STATUS_READY, self::DIGEST, 'base for tools'], [$image->status, $image->digest, $image->why]);
        $this->assertSame(['docker.pull', 'lines.tag', 'lines.pushQuietly', 'host.httpRequest'], $this->root->actions());
    }

    public function test_an_image_that_cannot_be_pulled_is_recorded_as_failed_with_the_way_out(): void
    {
        $this->root->on('docker.pull', "manifest unknown\n", 1);

        [$code, $out] = $this->run_('system:image:add', ['ref' => 'debian:nope', '--pull' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('debian:nope could not be published: manifest unknown', $out);
        $this->assertStringContainsString('run `pae system:image:refresh debian:nope`, or `pae system:image:remove debian:nope`.', $out);
        $this->assertSame(OperatorImage::STATUS_FAILED, OperatorImage::findByRef('debian:nope')->status);
    }

    public function test_the_listing_shows_operator_images_then_the_catalogue(): void
    {
        $catalogue = HostPrewarmPlan::available();
        if ($catalogue === []) {
            $this->markTestSkipped('the catalogue prewarms nothing');
        }
        config(['deploy.prewarm_images' => $catalogue[0]['id']]);
        $this->image(['ref' => 'mybase:1', 'source' => 'local', 'size_bytes' => 2048, 'why' => 'ours']);
        $this->image(['ref' => 'other:2', 'source' => 'pull', 'status' => OperatorImage::STATUS_FAILED]);

        [$code, $out] = $this->run_('system:image:list');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\| operator \| mybase:1\s+\| local\s+\| ready\s+\| unknown\s+\| 2048B\s+\| -\s+\| ours/i', $out);
        $this->assertMatchesRegularExpression('/\| operator \| other:2\s+\| pull\s+\| failed\s+\| unknown\s+\| -/', $out);
        $this->assertMatchesRegularExpression('/\| engine\s+\| '.preg_quote($catalogue[0]['ref'], '/').'\s+\| \w+\s+\| prewarmed\s+\| unknown/', $out);
        if (isset($catalogue[1])) {
            $this->assertMatchesRegularExpression('/\| engine\s+\| '.preg_quote($catalogue[1]['ref'], '/').'\s+\| \w+\s+\| on demand/', $out);
        }

        [$code, $out] = $this->run_('system:image:list', ['--operator' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('mybase:1', $out);
        $this->assertStringNotContainsString('| engine', $out);
    }

    public function test_an_empty_operator_listing_says_how_to_add_one(): void
    {
        $this->assertSame([0, "No operator image. Add one with `pae system:image:add`.\n"], $this->run_('system:image:list', ['--operator' => true]));
    }

    public function test_a_local_image_gone_from_the_host_keeps_what_the_registry_has(): void
    {
        $this->image(['ref' => 'mybase:1', 'source' => 'local', 'digest' => self::DIGEST]);

        $this->assertSame(
            [0, "mybase:1 is not on this host any more; the cache registry keeps the copy it has.\n"],
            $this->run_('system:image:refresh', ['ref' => 'mybase:1'])
        );
        $this->assertSame(['docker.imageId'], $this->root->actions());
    }

    public function test_a_refresh_pulls_again_and_says_what_the_registry_serves(): void
    {
        $this->image(['ref' => 'debian:bookworm-slim', 'source' => 'pull']);
        $this->registryHasDigest();

        [$code, $out] = $this->run_('system:image:refresh', ['ref' => 'debian:bookworm-slim']);

        $this->assertSame(0, $code, $out);
        $this->assertStringEndsWith('debian:bookworm-slim refreshed; the cache registry serves '.self::DIGEST.".\n", $out);
    }

    public function test_a_failed_refresh_keeps_the_registry_copy(): void
    {
        $this->image(['ref' => 'debian:bookworm-slim', 'source' => 'pull', 'digest' => self::DIGEST]);
        $this->root->on('docker.pull', '', 1, null, "toomanyrequests\n");

        [$code, $out] = $this->run_('system:image:refresh', ['ref' => 'debian:bookworm-slim']);

        $this->assertSame(1, $code);
        $this->assertSame("toomanyrequests\ndebian:bookworm-slim could not be refreshed: toomanyrequests\nThe cache registry keeps the copy it had.\n", $out);
        $this->assertSame(OperatorImage::STATUS_FAILED, OperatorImage::findByRef('debian:bookworm-slim')->status);
    }

    public function test_an_image_another_task_is_working_on_is_not_refreshed(): void
    {
        $task = Task::start(jobType: 'stub', queue: 'default', details: []);
        $this->image(['ref' => 'debian:bookworm-slim', 'source' => 'pull', 'task_id' => $task->id]);

        try {
            Artisan::call('system:image:refresh', ['ref' => 'debian:bookworm-slim']);
            $this->fail('a busy image was refreshed');
        } catch (ProblemException $e) {
            $this->assertStringContainsString(OperatorImages::BUSY, (string) json_encode($e->problems));
        }
        $this->assertSame([], $this->root->requests());
    }

    public function test_an_unknown_image_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Operator image 'nope:1' not found.");

        Artisan::call('system:image:remove', ['ref' => 'nope:1']);
    }

    public function test_removing_an_image_the_registry_does_not_hold_forgets_it(): void
    {
        $this->image(['ref' => 'mybase:1', 'source' => 'local']);
        $this->root->on('host.httpRequest', "HTTP/1.1 200 OK\r\n\r\n".json_encode(['repositories' => []]), 0, '/v2/_catalog');

        $this->assertSame(
            [0, "mybase:1 is no longer an operator image (not in the cache registry).\n"],
            $this->run_('system:image:remove', ['ref' => 'mybase:1'])
        );
        $this->assertNull(OperatorImage::findByRef('mybase:1'));
        $this->assertContains('docker.registryGarbageCollect', $this->root->actions());
    }

    public function test_a_removal_that_cannot_lock_the_registry_keeps_the_image(): void
    {
        putenv('PA_PUSH_LOCK='.sys_get_temp_dir().'/pa-no-such-dir-'.bin2hex(random_bytes(3)).'/lock');
        $this->image(['ref' => 'mybase:1', 'source' => 'local']);

        [$code, $out] = $this->run_('system:image:remove', ['ref' => 'mybase:1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('mybase:1 could not be removed: Could not lock', $out);
        $this->assertSame(OperatorImage::STATUS_FAILED, OperatorImage::findByRef('mybase:1')->status);
    }

    public function test_an_update_that_leaves_the_build_alone_rebuilds_nothing(): void
    {
        $this->image(['ref' => 'mybase:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE, 'build_args' => ['PHP' => '8.3']]);

        $this->assertSame(
            [0, "mybase:1 updated; its build did not change, so nothing was rebuilt.\n"],
            $this->run_('system:image:update', ['ref' => 'mybase:1', '--why' => 'a new note', '--build-arg' => ['PHP=8.3']])
        );
        $this->assertSame('a new note', OperatorImage::findByRef('mybase:1')->why);
        $this->assertSame([], $this->root->requests());
    }

    public function test_changed_build_arguments_rebuild_and_publish_the_image(): void
    {
        $this->image(['ref' => 'mybase:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE, 'build_args' => ['PHP' => '8.3']]);
        $this->registryHasDigest();

        [$code, $out] = $this->run_('system:image:update', ['ref' => 'mybase:1', '--build-arg' => ['PHP=8.4', 'EXTRA=a=b']]);

        $this->assertSame(0, $code, $out);
        $this->assertStringEndsWith('mybase:1 rebuilt; the cache registry serves '.self::DIGEST.".\n", $out);
        $this->assertSame(['PHP' => '8.4', 'EXTRA' => 'a=b'], OperatorImage::findByRef('mybase:1')->build_args);
        $this->assertContains('docker.buildFromStdin', $this->root->actions());
    }

    public function test_dropping_the_build_arguments_rebuilds_and_a_failed_build_keeps_the_registry_copy(): void
    {
        $this->image(['ref' => 'mybase:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE, 'build_args' => ['PHP' => '8.3']]);
        $this->root->on('docker.buildFromStdin', "ERROR: failed to solve\n", 1);

        [$code, $out] = $this->run_('system:image:update', ['ref' => 'mybase:1', '--no-build-args' => true]);

        $this->assertSame(1, $code);
        $this->assertSame("ERROR: failed to solve\nmybase:1 could not be rebuilt: ERROR: failed to solve\nThe cache registry keeps the copy it had.\n", $out);
        $this->assertSame([], OperatorImage::findByRef('mybase:1')->buildArgs());
    }
}
