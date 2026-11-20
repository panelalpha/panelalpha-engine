<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\CacheManager\BuildCacheBuilder;
use App\Lib\Deploy\CacheManager\BuildCacheProducer;
use App\Lib\Deploy\CacheManager\BuildCacheRefused;
use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\System;
use App\System\Privileged;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BuildCacheProducerTest extends TestCase
{
    private string $root;

    private string $work;

    private string $src;

    private string $outside;

    /** @var list<string> */
    private array $said = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-build-cache-'.bin2hex(random_bytes(4));
        $this->work = $this->root.'/work';
        $this->src = $this->work.'/src';
        $this->outside = $this->root.'/outside';
        mkdir($this->src, 0777, true);
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return System&object{calls: list<list<string>>} */
    private function host(bool $removalFails = false): System
    {
        return new class($removalFails) extends System
        {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(private readonly bool $removalFails) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = array_values((array) $cmd);
                $this->calls[] = $argv;
                if (($argv[0] ?? '') === 'git') {
                    throw new \RuntimeException('fatal: repository not found');
                }
                if ($this->removalFails && $argv === Privileged::files()->removeTree((string) end($argv))) {
                    throw new \RuntimeException('rm: cannot remove: Device or resource busy');
                }

                return '';
            }
        };
    }

    private function producer(System $host): BuildCacheProducer
    {
        return new BuildCacheProducer($host, new BuildCacheBuilder(null), function (string $line): void {
            $this->said[] = $line;
        });
    }

    /**
     * One build's cache, read and written as build() runs it: confined to the clone.
     *
     * @param  array{context?: string, dockerfile?: string}  $build
     */
    private function cache(System $host, array $build = []): ?string
    {
        $producer = $this->producer($host);
        $build += ['context' => '', 'dockerfile' => 'Dockerfile', 'target' => null, 'args' => []];
        $dockerfile = new \ReflectionMethod($producer, 'dockerfile');

        return AccountHomeRead::confinedTo($this->src, fn (): ?string => $dockerfile->invoke(
            $producer, 'https://github.com/acme/app', 'main', $this->work, $build['dockerfile'], $build
        ));
    }

    private function saidAbout(string $path): bool
    {
        return array_filter($this->said, static fn (string $line): bool => str_starts_with($line, $path.' ')) !== [];
    }

    /**
     * The work root is www-data's, so the directory is made without root, and
     * removed as root because the build containers leave root's files in it.
     */
    public function test_the_work_directory_is_made_for_the_builder_and_always_removed(): void
    {
        $host = $this->host();

        try {
            $this->producer($host)->build('https://github.com/acme/app', null);
            $this->fail('the failed clone should surface');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('repository not found', $e->getMessage());
        }

        [$mkdir, $clone] = $host->calls;
        $work = end($mkdir);
        $this->assertSame(['mkdir', '-p', '--'], array_slice($mkdir, 0, 3));
        $this->assertStringStartsWith(BuildCacheProducer::WORK_ROOT.'/', $work);
        $this->assertSame('git', $clone[0]);
        $this->assertSame(Privileged::files()->removeTree($work), end($host->calls));
    }

    public function test_a_removal_that_fails_is_said_and_the_builds_own_failure_stands(): void
    {
        $host = $this->host(removalFails: true);

        try {
            $this->producer($host)->build('https://github.com/acme/app', null);
            $this->fail('the failed clone should surface');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('repository not found', $e->getMessage());
        }

        $this->assertCount(1, $this->said);
        $this->assertStringStartsWith('Could not remove '.BuildCacheProducer::WORK_ROOT.'/', $this->said[0]);
        $this->assertStringContainsString('Device or resource busy', $this->said[0]);
    }

    public function test_a_url_that_is_not_plainly_public_runs_nothing(): void
    {
        $host = $this->host();

        $this->expectException(BuildCacheRefused::class);
        try {
            $this->producer($host)->build('https://bob:secret@github.com/acme/app', null);
        } finally {
            $this->assertSame([], $host->calls);
        }
    }

    public function test_the_deploys_ignore_file_is_written_into_the_clone_and_built_with(): void
    {
        file_put_contents($this->src.'/Dockerfile', "FROM alpine\nCOPY . .\n");
        file_put_contents($this->src.'/.dockerignore', "node_modules\n");
        $host = $this->host();

        $ref = $this->cache($host);

        $this->assertNotNull($ref);
        $written = (string) file_get_contents($this->src.'/Dockerfile.dockerignore');
        $this->assertStringStartsWith(BuildContextIgnore::MARKER, $written);
        $this->assertStringContainsString("node_modules\n", $written);
        $this->assertSame(['.', '..', '.dockerignore', 'Dockerfile', 'Dockerfile.dockerignore'], scandir($this->src));
        $this->assertCount(3, $host->calls);
    }

    public function test_a_dangling_link_at_the_ignore_files_name_is_not_written_through(): void
    {
        file_put_contents($this->src.'/Dockerfile', "FROM alpine\n");
        symlink($this->outside.'/created', $this->src.'/Dockerfile.dockerignore');
        $host = $this->host();

        $this->assertNull($this->cache($host));

        $this->assertFileDoesNotExist($this->outside.'/created');
        $this->assertTrue(is_link($this->src.'/Dockerfile.dockerignore'));
        $this->assertTrue($this->saidAbout('Dockerfile.dockerignore'));
        $this->assertSame([], $host->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function linkedOutFiles(): iterable
    {
        yield 'the Dockerfile' => ['Dockerfile'];
        yield 'the .dockerignore' => ['.dockerignore'];
        yield 'the ignore file the build reads' => ['Dockerfile.dockerignore'];
    }

    #[DataProvider('linkedOutFiles')]
    public function test_a_file_the_build_reads_linked_out_of_the_clone_is_neither_read_nor_built(string $file): void
    {
        file_put_contents($this->outside.'/secret', "FROM alpine\nSECRET-LINE\n");
        if ($file !== 'Dockerfile') {
            file_put_contents($this->src.'/Dockerfile', "FROM alpine\n");
        }
        symlink($this->outside.'/secret', $this->src.'/'.$file);
        $host = $this->host();

        $this->assertNull($this->cache($host));

        $this->assertTrue($this->saidAbout($file));
        $this->assertSame([], $host->calls);
        $this->assertSame("FROM alpine\nSECRET-LINE\n", file_get_contents($this->outside.'/secret'));
        if ($file !== 'Dockerfile.dockerignore') {
            $this->assertFileDoesNotExist($this->src.'/Dockerfile.dockerignore');
        }
    }

    public function test_a_link_to_another_file_of_the_clone_is_refused_too(): void
    {
        file_put_contents($this->src.'/Dockerfile.prod', "FROM alpine\n");
        symlink('Dockerfile.prod', $this->src.'/Dockerfile');
        $host = $this->host();

        $this->assertNull($this->cache($host));

        $this->assertTrue($this->saidAbout('Dockerfile'));
        $this->assertSame([], $host->calls);
    }

    public function test_a_context_folder_that_is_a_link_is_not_written_into(): void
    {
        mkdir($this->outside.'/web');
        file_put_contents($this->outside.'/web/Dockerfile', "FROM alpine\n");
        symlink($this->outside.'/web', $this->src.'/web');
        $host = $this->host();

        $this->assertNull($this->cache($host, ['context' => 'web']));

        $this->assertSame(['.', '..', 'Dockerfile'], scandir($this->outside.'/web'));
        $this->assertTrue($this->saidAbout('web'));
        $this->assertSame([], $host->calls);
    }

    public function test_a_context_below_a_linked_folder_is_not_written_into(): void
    {
        mkdir($this->outside.'/apps/web', 0777, true);
        file_put_contents($this->outside.'/apps/web/Dockerfile', "FROM alpine\n");
        symlink($this->outside.'/apps', $this->src.'/apps');
        $host = $this->host();

        $this->assertNull($this->cache($host, ['context' => 'apps/web']));

        $this->assertSame(['.', '..', 'Dockerfile'], scandir($this->outside.'/apps/web'));
        $this->assertSame([], $host->calls);
    }

    public function test_a_folder_at_the_ignore_files_name_is_no_cache_for_that_build_not_a_crash(): void
    {
        file_put_contents($this->src.'/Dockerfile', "FROM alpine\n");
        mkdir($this->src.'/Dockerfile.dockerignore');
        $host = $this->host();

        $this->assertNull($this->cache($host));

        $this->assertTrue($this->saidAbout('Dockerfile.dockerignore'));
        $this->assertSame([], $host->calls);
    }

    public function test_a_manifest_linked_out_of_the_clone_reads_as_missing(): void
    {
        file_put_contents($this->src.'/Dockerfile', "FROM node\nCOPY . .\nRUN npm ci\n");
        file_put_contents($this->outside.'/package.json', '{"dependencies":{"git-rev-sync":"3.0.2"}}');
        symlink($this->outside.'/package.json', $this->src.'/package.json');

        $this->assertNotNull($this->cache($this->host()));

        $this->assertStringContainsString("\n.git\n", (string) file_get_contents($this->src.'/Dockerfile.dockerignore'));
    }

    public function test_a_compose_file_linked_out_of_the_clone_builds_nothing(): void
    {
        file_put_contents($this->outside.'/compose.yml', "services:\n  app:\n    build: .\n");
        symlink($this->outside.'/compose.yml', $this->src.'/docker-compose.yml');
        $producer = $this->producer($this->host());
        $composeBuilds = new \ReflectionMethod($producer, 'composeBuilds');

        $builds = AccountHomeRead::confinedTo($this->src, fn (): array => $composeBuilds->invoke($producer, $this->src, 'docker-compose.yml'));

        $this->assertSame([], $builds);
    }
}
