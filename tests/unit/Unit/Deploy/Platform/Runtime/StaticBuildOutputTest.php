<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\StaticBuildOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * jaeger-ui's workspace member wrote packages/jaeger-ui/build/index.html beside the sources' own index.html and an
 * old docs prototype; only the page the build wrote is the site.
 */
class StaticBuildOutputTest extends TestCase
{
    private const STARTED = 1_800_000_000;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-static-output-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    public function test_only_an_index_written_since_the_build_started_is_found(): void
    {
        $this->old('packages/jaeger-ui/index.html');
        $this->old('docs/rfc/0009/prototype/index.html');
        $this->fresh('packages/jaeger-ui/build/index.html');

        $this->assertSame(['packages/jaeger-ui/build'], $this->found());
    }

    public function test_dependencies_public_and_hidden_directories_are_not_the_site(): void
    {
        $this->fresh('node_modules/pkg/dist/index.html');
        $this->fresh('public/index.html');
        $this->fresh('web/public/index.html');
        $this->fresh('.cache/index.html');
        $this->fresh('vendor/lib/index.html');

        $this->assertSame([], $this->found());
    }

    public function test_pages_below_an_output_belong_to_it(): void
    {
        $this->fresh('build/index.html');
        $this->fresh('build/docs/index.html');
        $this->fresh('build-extra/index.html');

        $this->assertSame(['build', 'build-extra'], $this->found());
    }

    public function test_the_project_root_is_never_an_output(): void
    {
        $this->fresh('index.html');

        $this->assertSame([], $this->found());
    }

    public function test_a_linked_page_or_directory_is_not_followed(): void
    {
        $this->fresh('outside/site/index.html');
        mkdir($this->dir.'/project/out', 0777, true);
        symlink($this->dir.'/outside/site/index.html', $this->dir.'/project/out/index.html');
        symlink($this->dir.'/outside/site', $this->dir.'/project/linked');

        $this->assertSame([], StaticBuildOutput::writtenSince($this->dir.'/project', self::STARTED));
    }

    public function test_a_missing_project_finds_nothing(): void
    {
        $this->assertSame([], StaticBuildOutput::writtenSince($this->dir.'/missing', self::STARTED));
    }

    /** @return list<string> */
    private function found(): array
    {
        return StaticBuildOutput::writtenSince($this->dir, self::STARTED);
    }

    private function fresh(string $relative): void
    {
        $this->write($relative, self::STARTED + 30);
    }

    private function old(string $relative): void
    {
        $this->write($relative, self::STARTED - 30);
    }

    private function write(string $relative, int $mtime): void
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, '<!doctype html>');
        touch($path, $mtime);
    }
}
