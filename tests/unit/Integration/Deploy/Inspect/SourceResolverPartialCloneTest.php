<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\BloblessCheckout;
use App\Lib\Deploy\Inspect\DetectionFiles;
use App\Lib\Deploy\Inspect\InspectException;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Http\OutboundUrl;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A clone not read from a GitHub file list fetches only the blobs detection
 * reads, from a local remote behind git's `insteadOf`.
 */
class SourceResolverPartialCloneTest extends TestCase
{
    private const URL = 'https://gitlab.com/owner/repo';

    private string $tmp = '';

    private string $origin = '';

    private ?string $home = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not on PATH');
        }
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
        $this->tmp = sys_get_temp_dir() . '/resolver-partial-' . bin2hex(random_bytes(6));
        $this->origin = $this->tmp . '/origin';
        mkdir($this->origin, 0700, true);
        mkdir($this->tmp . '/home', 0700, true);
        $this->git('init -q -b main');
        $this->git('config uploadpack.allowFilter true');

        file_put_contents(
            $this->tmp . '/home/.gitconfig',
            "[url \"file://{$this->origin}\"]\n\tinsteadOf = " . self::URL . "\n"
        );
        $this->home = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $_ENV['HOME'] = $this->tmp . '/home';
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        if ($this->home !== null) {
            $_SERVER['HOME'] = $_ENV['HOME'] = $this->home;
        }
        ResolvedSource::removeTree($this->tmp);
        parent::tearDown();
    }

    private function git(string $args): string
    {
        return trim((string) shell_exec(
            'git -C ' . escapeshellarg($this->origin) . ' -c user.name=t -c user.email=t@example.com ' . $args . ' 2>&1'
        ));
    }

    /**
     * @param array<string, string> $files
     */
    private function commit(array $files): string
    {
        foreach ($files as $path => $contents) {
            $file = $this->origin . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            file_put_contents($file, $contents);
        }
        $this->git('add -A');
        $this->git('commit -qm change');

        return $this->git('rev-parse HEAD');
    }

    private function resolve(?string $branch = null): ResolvedSource
    {
        return (new SourceResolver($this->tmp . '/work', 60, null, ['https', 'http', 'file']))->fromGit(self::URL, $branch);
    }

    private static function heldLocally(ResolvedSource $resolved, string $blob): bool
    {
        exec(
            'GIT_NO_LAZY_FETCH=1 git --git-dir=' . escapeshellarg(dirname($resolved->dir) . '/repo.git')
                . ' cat-file -e ' . escapeshellarg($blob) . ' 2>/dev/null',
            $output,
            $status
        );

        return $status === 0;
    }

    public function test_only_the_files_detection_reads_are_fetched(): void
    {
        $sha = $this->commit([
            'composer.json' => '{"require":{"php":"^8.3"}}',
            'index.php' => '<?php echo "hello";',
            'docs/guide.md' => '# Guide',
        ]);

        $resolved = $this->resolve();
        try {
            $this->assertSame(SourceResolver::METHOD_PARTIAL, $resolved->meta['method']);
            $this->assertSame($sha, $resolved->meta['commit']);
            $this->assertSame('main', $resolved->meta['branch']);
            $this->assertSame(self::URL, $resolved->meta['repository']);
            $this->assertSame('{"require":{"php":"^8.3"}}', file_get_contents($resolved->dir . '/composer.json'));
            $this->assertSame('', file_get_contents($resolved->dir . '/index.php'));
            $this->assertSame('', file_get_contents($resolved->dir . '/docs/guide.md'));
            $this->assertDirectoryExists($resolved->dir . '/.git');
            $this->assertFalse(self::heldLocally($resolved, $this->git('rev-parse HEAD:index.php')));
        } finally {
            $resolved->release();
        }
        $this->assertSame([], glob($this->tmp . '/work/inspect-*') ?: []);
    }

    public function test_the_projects_a_root_solution_lists_deeper_down_are_fetched_too(): void
    {
        $project = '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>';
        $props = '<Project><PropertyGroup><TargetFramework>net9.0</TargetFramework></PropertyGroup></Project>';
        $this->commit([
            'App.slnx' => '<Solution><Project Path="src/blazor/admin/Web/Web.csproj" /></Solution>',
            'src/blazor/admin/Web/Web.csproj' => $project,
            'src/blazor/admin/Web/Directory.Build.props' => $props,
            'src/blazor/admin/Web/Program.cs' => 'var app = WebApplication.Create();',
        ]);

        $resolved = $this->resolve();
        try {
            $this->assertSame(SourceResolver::METHOD_PARTIAL, $resolved->meta['method']);
            $this->assertSame($project, file_get_contents($resolved->dir . '/src/blazor/admin/Web/Web.csproj'));
            $this->assertSame($props, file_get_contents($resolved->dir . '/src/blazor/admin/Web/Directory.Build.props'));
            $this->assertSame('', file_get_contents($resolved->dir . '/src/blazor/admin/Web/Program.cs'));
        } finally {
            $resolved->release();
        }
    }

    public function test_a_link_inside_the_repository_is_a_link_again(): void
    {
        mkdir($this->origin . '/docker');
        file_put_contents($this->origin . '/docker/Dockerfile', "FROM nginx:alpine\n");
        symlink('docker/Dockerfile', $this->origin . '/Dockerfile');
        $this->commit([]);

        $resolved = $this->resolve();
        try {
            $this->assertSame(SourceResolver::METHOD_PARTIAL, $resolved->meta['method']);
            $this->assertTrue(is_link($resolved->dir . '/Dockerfile'));
            $this->assertSame("FROM nginx:alpine\n", file_get_contents($resolved->dir . '/Dockerfile'));
        } finally {
            $resolved->release();
        }
    }

    public function test_the_branch_asked_for_is_the_one_read(): void
    {
        $this->commit(['composer.json' => '{"require":{"php":"^8.2"}}']);
        $this->git('checkout -q -b next');
        $sha = $this->commit(['composer.json' => '{"require":{"php":"^8.4"}}']);
        $this->git('checkout -q main');

        $resolved = $this->resolve('next');
        try {
            $this->assertSame('next', $resolved->meta['branch']);
            $this->assertSame($sha, $resolved->meta['commit']);
            $this->assertStringContainsString('^8.4', (string) file_get_contents($resolved->dir . '/composer.json'));
        } finally {
            $resolved->release();
        }
    }

    public function test_a_server_that_ignores_the_filter_is_still_written_out_partially(): void
    {
        $this->git('config --unset uploadpack.allowFilter');
        $this->commit(['composer.json' => '{}', 'index.php' => '<?php echo "hello";']);

        $resolved = $this->resolve();
        try {
            $this->assertSame(SourceResolver::METHOD_PARTIAL, $resolved->meta['method']);
            $this->assertSame('{}', file_get_contents($resolved->dir . '/composer.json'));
            $this->assertSame('', file_get_contents($resolved->dir . '/index.php'));
        } finally {
            $resolved->release();
        }
    }

    public function test_submodules_are_left_to_the_full_clone(): void
    {
        $sha = $this->commit(['composer.json' => '{}']);
        $this->git('update-index --add --cacheinfo 160000,' . $sha . ',vendored');
        $this->git('commit -qm submodule');

        $resolved = $this->resolve();
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
        } finally {
            $resolved->release();
        }
        $this->assertSame([], glob($this->tmp . '/work/inspect-*') ?: []);
    }

    public function test_a_decisive_file_larger_than_detection_reads_is_left_to_the_full_clone(): void
    {
        $this->commit(['package.json' => '{"description":"' . str_repeat('x', DetectionFiles::MAX_BYTES) . '"}']);

        $resolved = $this->resolve();
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
        } finally {
            $resolved->release();
        }
    }

    public function test_a_repository_that_cannot_be_cloned_is_an_error_that_leaves_nothing_behind(): void
    {
        ResolvedSource::removeTree($this->origin);

        try {
            $this->resolve();
            $this->fail('A missing repository was resolved.');
        } catch (InspectException $e) {
            $this->assertStringStartsWith('Could not clone the repository', $e->getMessage());
        }
        $this->assertSame([], glob($this->tmp . '/work/inspect-*') ?: []);
    }

    public function test_batch_output_is_read_by_size_whatever_the_contents_hold(): void
    {
        $body = "line one\n0123456789abcdef0123456789abcdef01234567 blob 3\nabc";
        $output = "aaaa blob " . strlen($body) . "\n{$body}\nbbbb missing\ncccc blob 0\n\n";

        $this->assertSame(['aaaa' => $body, 'cccc' => ''], BloblessCheckout::parseBatch($output));
    }
}
