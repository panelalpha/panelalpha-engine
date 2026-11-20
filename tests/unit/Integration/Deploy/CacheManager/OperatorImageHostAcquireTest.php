<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\OperatorImageHost;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\OperatorImage;
use App\System;
use App\System\Privileged;
use App\System\Privileged\Root;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * How the host fetches, builds, checks and drops an operator image, and what removing one from the registry reports.
 */
class OperatorImageHostAcquireTest extends TestCase
{
    private ?string $lock = null;

    protected function tearDown(): void
    {
        if ($this->lock !== null) {
            putenv('PA_PUSH_LOCK='.$this->lock);
        }
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $tags  ref => digest
     */
    private function host(AcquireHost $system, array $tags = [], bool $listable = true, bool $deletable = true, ?int $size = null): OperatorImageHost
    {
        $send = static function (string $method, string $url) use ($tags, $listable, $deletable): ?array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/v2/_catalog') {
                $repositories = array_values(array_unique(array_map(static fn (string $ref): string => explode(':', $ref)[0], array_keys($tags))));

                return $listable ? ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['repositories' => $repositories])] : null;
            }
            if (preg_match('#^/v2/(.+)/tags/list$#', $path, $m) === 1) {
                $listed = [];
                foreach (array_keys($tags) as $ref) {
                    if (explode(':', $ref)[0] === $m[1]) {
                        $listed[] = explode(':', $ref)[1];
                    }
                }

                return ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['tags' => $listed])];
            }
            preg_match('#^/v2/(.+)/manifests/(.+)$#', $path, $m);
            if ($method === 'DELETE') {
                return ['status' => $deletable ? 202 : 405, 'headers' => [], 'body' => ''];
            }
            $digest = $tags["{$m[1]}:{$m[2]}"] ?? null;

            return $digest === null
                ? ['status' => 404, 'headers' => [], 'body' => '']
                : ['status' => 200, 'headers' => ['docker-content-digest' => $digest], 'body' => ''];
        };
        $config = new RegistryImageConfig(static fn (string $url): ?string => $size === null || ! str_contains($url, '/manifests/')
            ? null
            : (string) json_encode(['layers' => [['size' => $size]]]), 'amd64');

        return new OperatorImageHost($system, new DindImageStore, new CacheRegistry($send, 'http://registry.test'), $config);
    }

    private function image(string $source, string $ref = 'whmcs-dev-base:php8.3'): OperatorImage
    {
        return new OperatorImage(['ref' => $ref, 'source' => $source, 'dockerfile' => "FROM debian:12\n", 'build_args' => ['PHP' => '8.3']]);
    }

    public function test_a_pulled_image_is_pulled_on_the_host_and_its_output_passed_on(): void
    {
        $system = new AcquireHost(output: "Pulling\nDone\n");
        $said = [];

        $this->host($system)->acquire($this->image(OperatorImage::SOURCE_PULL), false, function (string $line) use (&$said): void {
            $said[] = $line;
        });

        $this->assertSame([Privileged::docker()->pull('whmcs-dev-base:php8.3')], $system->processes);
        $this->assertSame(1800, $system->timeouts[0]);
        $this->assertSame(["Pulling\nDone\n"], $said);
    }

    public function test_a_built_image_is_built_from_its_dockerfile_with_its_args(): void
    {
        $system = new AcquireHost;

        $this->host($system)->acquire($this->image(OperatorImage::SOURCE_BUILD), true, static function (): void {});

        $this->assertSame([OperatorImageHost::buildCommand('whmcs-dev-base:php8.3', "FROM debian:12\n", ['PHP' => '8.3'], true)], $system->processes);
        $this->assertSame(OperatorImageHost::BUILD_TIMEOUT_SECONDS, $system->timeouts[0]);
    }

    public function test_a_failed_run_throws_the_end_of_its_output(): void
    {
        $system = new AcquireHost(output: "step 1\nerror: no such image\n", exitCode: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("step 1\nerror: no such image");

        $this->host($system)->acquire($this->image(OperatorImage::SOURCE_PULL), false, static function (): void {});
    }

    public function test_a_failed_run_with_no_output_names_its_exit_code(): void
    {
        $system = new AcquireHost(exitCode: 125);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exit code 125');

        $this->host($system)->publish('whmcs-dev-base:php8.3', static function (): void {});
    }

    public function test_publishing_runs_the_stores_host_publish_command(): void
    {
        $system = new AcquireHost;

        $this->host($system)->publish('whmcs-dev-base:php8.3', static function (): void {});

        $this->assertSame([(new DindImageStore)->hostPublishCommand('whmcs-dev-base:php8.3')], $system->processes);
    }

    public function test_a_local_image_already_on_the_host_runs_nothing_more(): void
    {
        $system = new AcquireHost(imageId: "sha256:abc\n");

        $this->host($system)->acquire($this->image(OperatorImage::SOURCE_LOCAL), false, static function (): void {});

        $this->assertSame([Privileged::docker()->imageId('whmcs-dev-base:php8.3')], $system->commands);
        $this->assertSame([], $system->processes);
    }

    public function test_a_local_image_missing_from_the_host_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('whmcs-dev-base:php8.3 is not on this host. Build or load it with docker first.');

        $this->host(new AcquireHost)->acquire($this->image(OperatorImage::SOURCE_LOCAL), false, static function (): void {});
    }

    public function test_an_inspect_that_fails_is_not_on_the_host(): void
    {
        $this->assertFalse($this->host(new AcquireHost(failing: 'docker.imageId'))->isOnHost('a:1'));
        $this->assertTrue($this->host(new AcquireHost(imageId: 'sha256:abc'))->isOnHost('a:1'));
    }

    public function test_measuring_reads_the_digest_and_the_layers_a_pull_moves(): void
    {
        $this->assertSame(['digest' => 'sha256:a', 'size' => 1234], $this->host(new AcquireHost, ['a:1' => 'sha256:a'], size: 1234)->measure('a:1'));
        $this->assertSame(['digest' => null, 'size' => null], $this->host(new AcquireHost, ['a:1' => 'sha256:a'], size: 1234)->measure('b:1'));
    }

    public function test_dropping_a_pulled_image_removes_the_registry_copy_and_its_own_tag(): void
    {
        $system = new AcquireHost(failing: 'docker.removeImages');

        $this->host($system)->dropHostCopies($this->image(OperatorImage::SOURCE_PULL, 'a:1'));

        $this->assertSame([
            Privileged::docker()->removeImages(DindImageStore::HOST_CACHE_REGISTRY.'/a:1'),
            Privileged::docker()->removeImages('a:1'),
        ], $system->commands);
    }

    public function test_dropping_a_local_image_keeps_the_operators_own_tag(): void
    {
        $system = new AcquireHost;

        $this->host($system)->dropHostCopies($this->image(OperatorImage::SOURCE_LOCAL, 'a:1'));

        $this->assertSame([Privileged::docker()->removeImages(DindImageStore::HOST_CACHE_REGISTRY.'/a:1')], $system->commands);
    }

    public function test_an_unlistable_registry_removes_nothing(): void
    {
        $system = new AcquireHost;

        try {
            $this->host($system, ['a:1' => 'sha256:a'], listable: false)->unpublish('a:1');
            $this->fail('an unlistable registry should be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('Could not list the cache registry.', $e->getMessage());
        }
        $this->assertSame([], $system->commands);
    }

    public function test_a_registry_that_refuses_the_delete_is_reported_and_still_collected(): void
    {
        $system = new AcquireHost;

        $outcome = $this->host($system, ['a:1' => 'sha256:a', 'b:1' => 'sha256:b'], deletable: false)->unpublish('a:1');

        $this->assertSame('the cache registry refused to remove it', $outcome);
        $this->assertSame([DindImageStore::garbageCollectArgv()], $system->commands);
    }

    public function test_a_lock_that_cannot_be_taken_leaves_the_registry_alone(): void
    {
        $this->lock = (string) getenv('PA_PUSH_LOCK');
        putenv('PA_PUSH_LOCK='.sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'/cache-registry.lock');
        $system = new AcquireHost;

        try {
            $this->host($system, ['a:1' => 'sha256:a'])->unpublish('a:1');
            $this->fail('an unlockable registry should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the cache registry was left as it is', $e->getMessage());
        }
        $this->assertSame([], $system->commands);
    }

    public function test_make_builds_a_host_for_the_default_engine(): void
    {
        $this->assertInstanceOf(OperatorImageHost::class, OperatorImageHost::make());
    }
}

class AcquireHost extends System
{
    /** @var list<string|list<string>> */
    public array $commands = [];

    /** @var list<string|list<string>> */
    public array $processes = [];

    /** @var list<int> */
    public array $timeouts = [];

    public function __construct(
        private readonly string $output = '',
        private readonly int $exitCode = 0,
        private readonly string $imageId = '',
        private readonly ?string $failing = null,
    ) {}

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->commands[] = $cmd;
        if (is_array($cmd) && $this->failing !== null && Root::askedAction($cmd) === $this->failing) {
            throw new \RuntimeException('Error response from daemon');
        }

        return is_array($cmd) && Root::askedAction($cmd) === 'docker.imageId' ? $this->imageId : '';
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        $this->processes[] = $cmd;
        $this->timeouts[] = $timeout;
        if ($this->output !== '' && $onOutput !== null) {
            $onOutput(Process::OUT, $this->output);
        }

        return FakeProcess::of($this->exitCode, $this->output);
    }
}
