<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\SourceResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A public github.com repository is read from its file list first, and cloned
 * whenever that cannot be done exactly. The clone here is redirected to a
 * local repository with git's `insteadOf`, so no test touches the network.
 */
class SourceResolverTreeTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const URL = 'https://github.com/owner/repo';

    private string $tmp = '';

    private ?string $home = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not on PATH');
        }
        $this->tmp = sys_get_temp_dir() . '/resolver-tree-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/origin', 0700, true);
        mkdir($this->tmp . '/home', 0700, true);

        $origin = $this->tmp . '/origin';
        file_put_contents($origin . '/composer.json', '{"require":{"php":"^8.3"}}');
        $git = 'git -C ' . escapeshellarg($origin) . ' -c user.name=t -c user.email=t@example.com ';
        exec($git . 'init -q -b main && ' . $git . 'add -A && ' . $git . 'commit -qm init');

        file_put_contents(
            $this->tmp . '/home/.gitconfig',
            "[url \"file://{$origin}\"]\n\tinsteadOf = " . self::URL . "\n\tinsteadOf = https://gitlab.com/owner/repo\n"
        );
        $this->home = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $_ENV['HOME'] = $this->tmp . '/home';
    }

    protected function tearDown(): void
    {
        if ($this->home !== null) {
            $_SERVER['HOME'] = $_ENV['HOME'] = $this->home;
        }
        ResolvedSource::removeTree($this->tmp);
        parent::tearDown();
    }

    private function resolver(): SourceResolver
    {
        $tree = new GitHubTree(static fn (string $url, ?string $branch): array => [self::SHA, 'main']);

        return new SourceResolver($this->tmp . '/work', 60, $tree);
    }

    private static function fakeTree(int $status = 200): void
    {
        $composer = '{"require":{"php":"^8.2"}}';
        Http::fake([
            'api.github.com/repos/owner/repo/git/trees/*' => Http::response(
                ['tree' => [['path' => 'composer.json', 'mode' => '100644', 'type' => 'blob', 'size' => strlen($composer)]]],
                $status
            ),
            'raw.githubusercontent.com/owner/repo/' . self::SHA . '/composer.json' => Http::response($composer),
        ]);
    }

    public function test_a_public_github_repository_is_read_from_its_tree(): void
    {
        self::fakeTree();

        $resolved = $this->resolver()->fromGit('github.com/owner/repo');
        try {
            $this->assertSame(SourceResolver::METHOD_TREE, $resolved->meta['method']);
            $this->assertSame(self::SHA, $resolved->meta['commit']);
            $this->assertSame('main', $resolved->meta['branch']);
            $this->assertSame(self::URL, $resolved->meta['repository']);
            $this->assertStringContainsString('^8.2', (string) file_get_contents($resolved->dir . '/composer.json'));
        } finally {
            $resolved->release();
        }
        $this->assertDirectoryDoesNotExist($resolved->dir);
    }

    public function test_a_tree_that_cannot_be_read_falls_back_to_the_clone(): void
    {
        self::fakeTree(404);

        $resolved = $this->resolver()->fromGit(self::URL);
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
            // The clone's own copy, not the tree's.
            $this->assertStringContainsString('^8.3', (string) file_get_contents($resolved->dir . '/composer.json'));
        } finally {
            $resolved->release();
        }
        // Nothing of the abandoned tree is left behind.
        $this->assertSame([], glob($this->tmp . '/work/inspect-*') ?: []);
    }

    public function test_a_token_means_a_private_repository_and_goes_straight_to_the_clone(): void
    {
        Http::fake();

        $resolved = $this->resolver()->fromGit(self::URL, null, 'ghp_secret');
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
        } finally {
            $resolved->release();
        }
        Http::assertNothingSent();
    }

    public function test_another_host_is_cloned_without_asking_github(): void
    {
        Http::fake();

        $resolved = $this->resolver()->fromGit('https://gitlab.com/owner/repo');
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
        } finally {
            $resolved->release();
        }
        Http::assertNothingSent();
    }

    public function test_a_resolver_without_a_tree_reader_always_clones(): void
    {
        Http::fake();

        $resolved = (new SourceResolver($this->tmp . '/work', 60))->fromGit(self::URL);
        try {
            $this->assertSame(SourceResolver::METHOD_CLONE, $resolved->meta['method']);
        } finally {
            $resolved->release();
        }
        Http::assertNothingSent();
    }
}
