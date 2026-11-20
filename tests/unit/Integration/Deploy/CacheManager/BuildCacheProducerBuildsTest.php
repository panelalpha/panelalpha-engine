<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\CacheManager\BuildCacheBuilder;
use App\Lib\Deploy\CacheManager\BuildCacheProducer;
use App\Lib\Deploy\CacheManager\BuildCacheRefused;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\OperatorImage;
use App\System;
use App\System\Filesystem;
use App\System\Privileged\Root;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What a producer builds and publishes for a clone: one cache per build a deploy runs, each published under its own ref.
 */
class BuildCacheProducerBuildsTest extends TestCase
{
    use InMemoryDatabase;

    private const REPOSITORY = 'https://github.com/acme/app';

    private string $work;

    private string $src;

    /** @var list<string> */
    private array $said = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->work = sys_get_temp_dir().'/pa-build-cache-builds-'.bin2hex(random_bytes(4));
        $this->src = $this->work.'/src';
        mkdir($this->src, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->work));
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files
     */
    private function clone(array $files): void
    {
        foreach ($files as $path => $contents) {
            if (! is_dir(dirname($this->src.'/'.$path))) {
                mkdir(dirname($this->src.'/'.$path), 0777, true);
            }
            file_put_contents($this->src.'/'.$path, $contents);
        }
    }

    private function producer(ProducerHost $host): BuildCacheProducer
    {
        return new BuildCacheProducer($host, new BuildCacheBuilder(null), function (string $line): void {
            $this->said[] = $line;
        });
    }

    /** @return list<string> */
    private function buildAll(ProducerHost $host, ?string $branch = 'main'): array
    {
        $producer = $this->producer($host);
        $buildAll = new \ReflectionMethod($producer, 'buildAll');

        return AccountHomeRead::confinedTo($this->src, fn (): array => $buildAll->invoke($producer, self::REPOSITORY, $branch, $this->work));
    }

    public function test_a_repository_with_a_dockerfile_publishes_one_cache_under_its_branch(): void
    {
        $this->clone(['Dockerfile' => "FROM alpine\nCOPY . .\n"]);
        $host = new ProducerHost;

        $refs = $this->buildAll($host);

        $ref = (string) BuildCache::ref(self::REPOSITORY, 'main', 'Dockerfile');
        $this->assertSame([$ref], $refs);
        $this->assertSame(['build-cache.buildKit', 'scripts.publishBuildCache'], $host->actions());
        $this->assertContains(BuildCache::hostRef($ref), $host->call('scripts.publishBuildCache'));
        $this->assertSame("Building {$ref}", $this->said[0]);
        $this->assertMatchesRegularExpression('/^Published '.preg_quote($ref, '/').': build \d+\.\ds, publish \d+\.\ds$/', $this->said[1]);
    }

    public function test_a_build_that_fails_is_said_and_publishes_nothing(): void
    {
        $this->clone(['Dockerfile' => "FROM alpine\n"]);
        $host = new ProducerHost(failing: ['build-cache.buildKit' => "#5 RUN make\nerror: one\nerror: two\nerror: three\n"]);

        $this->assertSame([], $this->buildAll($host));

        $this->assertNotContains('scripts.publishBuildCache', $host->actions());
        $this->assertStringEndsWith('failed: error: one | error: two | error: three', $this->said[1]);
        $this->assertSame('No cache for Dockerfile.', $this->said[2]);
    }

    public function test_each_compose_build_gets_its_own_cache(): void
    {
        $this->clone([
            'docker-compose.yml' => "services:\n  web:\n    build: .\n  worker:\n    build:\n      context: worker\n      target: run\n",
            'Dockerfile' => "FROM alpine\n",
            'worker/Dockerfile' => "FROM alpine AS run\n",
        ]);
        $host = new ProducerHost;

        $refs = $this->buildAll($host);

        $this->assertCount(2, $refs);
        $this->assertCount(2, array_unique($refs));
        $this->assertSame(2, count(array_keys($host->actions(), 'scripts.publishBuildCache', true)));
        $this->assertFileExists($this->src.'/Dockerfile.dockerignore');
        $this->assertFileExists($this->src.'/worker/Dockerfile.dockerignore');
    }

    public function test_a_compose_file_that_includes_another_builds_what_the_included_file_declares(): void
    {
        $this->clone([
            'docker-compose.yml' => "include:\n  - services.yml\n",
            'services.yml' => "services:\n  api:\n    build: .\n",
            'Dockerfile' => "FROM alpine\n",
        ]);
        $host = new ProducerHost;

        $this->assertCount(1, $this->buildAll($host));
        $this->assertSame(['build-cache.buildKit', 'scripts.publishBuildCache'], $host->actions());
    }

    public function test_a_compose_file_below_the_root_builds_its_paths_from_its_own_folder(): void
    {
        $this->clone([
            'deploy/docker-compose.yml' => "services:\n  app:\n    build:\n      context: ..\n      dockerfile: deploy/app.Dockerfile\n",
            'deploy/app.Dockerfile' => "FROM alpine\n",
        ]);
        $producer = $this->producer(new ProducerHost);
        $composeBuilds = new \ReflectionMethod($producer, 'composeBuilds');

        $builds = AccountHomeRead::confinedTo($this->src, fn (): array => $composeBuilds->invoke($producer, $this->src, 'deploy/docker-compose.yml'));

        $this->assertSame(['app'], array_keys($builds));
        $this->assertSame('deploy/app.Dockerfile', $builds['app']['dockerfile']);
        $this->assertContains($builds['app']['context'], ['', '.']);
    }

    public function test_a_repository_with_its_own_app_config_is_refused(): void
    {
        $this->clone(['panelalpha.yaml' => "app: |\n  echo hi\n", 'Dockerfile' => "FROM alpine\n"]);
        $host = new ProducerHost;

        try {
            $this->buildAll($host);
            $this->fail('an app config should refuse the cache');
        } catch (BuildCacheRefused $e) {
            $this->assertStringContainsString('app config', $e->getMessage());
        }
        $this->assertSame([], $host->actions());
    }

    public function test_a_repository_that_builds_no_image_is_refused_with_its_strategy(): void
    {
        $this->clone(['index.html' => '<html></html>']);

        $this->expectException(BuildCacheRefused::class);
        $this->expectExceptionMessage('strategy: static');

        $this->buildAll(new ProducerHost);
    }

    public function test_an_ignore_file_already_in_the_clone_is_built_with_as_it_is(): void
    {
        $this->clone(['Dockerfile' => "FROM alpine\n", 'Dockerfile.dockerignore' => "vendor\n"]);
        $host = new ProducerHost;

        $this->assertCount(1, $this->buildAll($host));
        $this->assertSame("vendor\n", file_get_contents($this->src.'/Dockerfile.dockerignore'));
    }

    public function test_a_dockerfile_that_uses_git_has_no_cache_since_no_other_checkout_hashes_the_same(): void
    {
        $this->clone(['Dockerfile' => "FROM alpine\nCOPY . .\nRUN git rev-parse HEAD > /version\n"]);
        $host = new ProducerHost;

        $this->assertSame([], $this->buildAll($host));

        $this->assertSame([], $host->actions());
        $this->assertStringStartsWith('Dockerfile keeps .git in its context because its Dockerfile uses git', $this->said[0]);
        $this->assertFileDoesNotExist($this->src.'/Dockerfile.dockerignore');
    }

    public function test_a_clone_that_is_gone_builds_nothing(): void
    {
        $producer = $this->producer(new ProducerHost);
        $dockerfile = new \ReflectionMethod($producer, 'dockerfile');
        $build = ['context' => '', 'dockerfile' => 'Dockerfile', 'target' => null, 'args' => []];

        $this->assertNull($dockerfile->invoke($producer, self::REPOSITORY, 'main', $this->work.'/missing', 'Dockerfile', $build));
    }

    public function test_an_operator_base_image_is_copied_out_and_built_from_its_layout(): void
    {
        OperatorImage::query()->create(['ref' => 'whmcs-dev-base:php8.3', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);
        $this->clone(['Dockerfile' => "FROM whmcs-dev-base:php8.3\nCOPY . .\n"]);
        $host = new ProducerHost(layoutDigest: 'sha256:abc');

        $this->assertCount(1, $this->buildAll($host));

        $this->assertSame(['build-cache.copyImageToLayout', 'build-cache.buildKit', 'scripts.publishBuildCache'], $host->actions());
        $this->assertContains('context:whmcs-dev-base:php8.3=oci-layout://base0:php8.3@sha256:abc', $host->call('build-cache.buildKit'));
    }

    public function test_an_operator_image_the_registry_cannot_hand_out_is_said_and_built_without(): void
    {
        OperatorImage::query()->create(['ref' => 'whmcs-dev-base:php8.3', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);
        $this->clone(['Dockerfile' => "FROM whmcs-dev-base:php8.3\n"]);
        $host = new ProducerHost;

        $this->assertCount(1, $this->buildAll($host));

        $this->assertSame('Could not copy whmcs-dev-base:php8.3 out of the cache registry; the build cannot start FROM it.', $this->said[0]);
        $this->assertSame([], array_filter($host->call('build-cache.buildKit'), static fn (string $word): bool => str_contains($word, 'oci-layout')));
    }

    public function test_the_default_branch_is_the_one_the_clone_checked_out(): void
    {
        $host = new ProducerHost(headBranch: "trunk\n");

        try {
            $this->producer($host)->build(self::REPOSITORY, null);
            $this->fail('an empty clone builds nothing');
        } catch (BuildCacheRefused $e) {
            $this->assertStringContainsString('build nothing', $e->getMessage());
        }

        $git = array_values(array_filter($host->calls, static fn (array $argv): bool => $argv[0] === 'git'));
        $this->assertSame('clone', $git[0][3]);
        $this->assertSame(['symbolic-ref', '--short', 'HEAD'], array_slice($git[1], 3));
        $this->assertSame('files.removeTree', Root::askedAction(end($host->calls)));
    }

    public function test_make_wires_the_configured_build_network(): void
    {
        $this->assertInstanceOf(BuildCacheProducer::class, BuildCacheProducer::make());
    }
}

class ProducerHost extends System
{
    /** @var list<list<string>> */
    public array $calls = [];

    /**
     * @param  array<string, string>  $failing  action => output it fails with
     */
    public function __construct(
        private readonly array $failing = [],
        private readonly ?string $layoutDigest = null,
        private readonly string $headBranch = '',
    ) {}

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $argv = array_values((array) $cmd);
        $this->calls[] = $argv;
        $action = Root::askedAction($argv);
        if ($action !== null && isset($this->failing[$action])) {
            throw new \RuntimeException($this->failing[$action]);
        }
        if (($argv[0] ?? '') === 'mkdir' && str_starts_with((string) end($argv), sys_get_temp_dir().'/')) {
            @mkdir((string) end($argv), 0777, true);
        }
        if ($action === 'build-cache.copyImageToLayout' && $this->layoutDigest !== null) {
            [, , $dir, $tag] = Root::askedValues($argv);
            file_put_contents($dir.'/index.json', (string) json_encode(['manifests' => [[
                'digest' => $this->layoutDigest,
                'annotations' => ['org.opencontainers.image.ref.name' => $tag],
            ]]]));
        }

        return ($argv[0] ?? '') === 'git' && in_array('symbolic-ref', $argv, true) ? $this->headBranch : '';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->calls[] = array_values((array) $cmd);

        return FakeProcess::ok();
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        $this->calls[] = array_values((array) $cmd);

        return FakeProcess::ok();
    }

    public function filesystem(): Filesystem
    {
        return new class($this) extends Filesystem
        {
            public function fileExists(string $path): bool
            {
                return is_file($path);
            }

            public function directoryExists(string $path): bool
            {
                return is_dir($path);
            }

            public function fileGetContents(string $path): string
            {
                return (string) file_get_contents($path);
            }
        };
    }

    /** @return list<string> the broker actions run, mkdir and git left out */
    public function actions(): array
    {
        return array_values(array_filter(array_map(Root::askedAction(...), $this->calls), static fn (?string $action): bool => $action !== null && $action !== 'files.removeTree'));
    }

    /** @return list<string> */
    public function call(string $action): array
    {
        foreach ($this->calls as $argv) {
            if (Root::askedAction($argv) === $action) {
                return $argv;
            }
        }

        return [];
    }
}
