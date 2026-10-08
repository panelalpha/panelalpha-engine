<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\DetectionFiles;
use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\TreeUnavailable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A public GitHub repository written out from one trees request plus the raw
 * files detection reads. Every case it cannot reproduce exactly must throw
 * TreeUnavailable, which sends the inspection to a clone.
 */
class GitHubTreeTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const TREE_URL = 'api.github.com/repos/owner/repo/git/trees/' . self::SHA . '*';

    private const RAW = 'raw.githubusercontent.com/owner/repo/' . self::SHA . '/';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gh-tree-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ResolvedSource::removeTree($this->dir);
        parent::tearDown();
    }

    private static function tree(): GitHubTree
    {
        return new GitHubTree(static fn (string $url, ?string $branch): array => [self::SHA, 'main']);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private static function treeBody(array $entries, bool $truncated = false): array
    {
        return ['sha' => 'f00', 'tree' => $entries, 'truncated' => $truncated];
    }

    private static function blob(string $path, int $size, string $mode = '100644'): array
    {
        return ['path' => $path, 'mode' => $mode, 'type' => 'blob', 'sha' => 'x', 'size' => $size];
    }

    private static function dir(string $path): array
    {
        return ['path' => $path, 'mode' => '040000', 'type' => 'tree', 'sha' => 'x'];
    }

    /**
     * @return array<string, array{0: string, 1: ?array{0: string, 1: string}}>
     */
    public static function urls(): array
    {
        return [
            'plain' => ['https://github.com/owner/repo', ['owner', 'repo']],
            'with .git' => ['https://github.com/owner/repo.git', ['owner', 'repo']],
            'trailing slash' => ['https://github.com/owner/repo/', ['owner', 'repo']],
            'case of the host' => ['https://GitHub.com/Owner/Repo', ['Owner', 'Repo']],
            'http' => ['http://github.com/owner/repo', null],
            'another forge' => ['https://gitlab.com/owner/repo', null],
            'credentials' => ['https://user:token@github.com/owner/repo', null],
            'a page inside it' => ['https://github.com/owner/repo/tree/main', null],
            'ssh' => ['git@github.com:owner/repo.git', null],
            'a query' => ['https://github.com/owner/repo?tab=readme', null],
        ];
    }

    /**
     * @param ?array{0: string, 1: string} $expected
     */
    #[DataProvider('urls')]
    public function test_only_a_plain_github_https_url_is_read_from_its_tree(string $url, ?array $expected): void
    {
        $this->assertSame($expected, GitHubTree::repository($url));
    }

    public function test_ls_remote_gives_the_commit_and_the_default_branch(): void
    {
        $output = "ref: refs/heads/13.x\tHEAD\n" . self::SHA . "\tHEAD\n";

        $this->assertSame([self::SHA, '13.x'], GitHubTree::parseLsRemote($output, null));
    }

    public function test_ls_remote_prefers_a_branch_then_the_commit_a_tag_points_at(): void
    {
        $tagObject = str_repeat('a', 40);
        $output = $tagObject . "\trefs/tags/v1\n" . self::SHA . "\trefs/tags/v1^{}\n";

        $this->assertSame([self::SHA, null], GitHubTree::parseLsRemote($output, 'v1'));
        $this->assertSame(
            [$tagObject, null],
            GitHubTree::parseLsRemote($tagObject . "\trefs/heads/v1\n" . $output, 'v1')
        );
    }

    public function test_a_ref_ls_remote_does_not_list_is_unavailable(): void
    {
        $this->expectException(TreeUnavailable::class);
        GitHubTree::parseLsRemote('', 'nope');
    }

    public function test_writes_every_file_empty_and_fetches_the_ones_detection_reads(): void
    {
        $package = '{"name":"x","scripts":{"start":"node index.js"}}';
        $compose = "services:\n  app:\n    build: .\n";
        Http::fake([
            self::TREE_URL => Http::response(self::treeBody([
                self::blob('package.json', strlen($package)),
                self::blob('index.js', 500),
                self::dir('docker'),
                self::blob('docker/compose.yml', strlen($compose)),
                self::blob('compose.yml', strlen('docker/compose.yml'), DetectionFiles::SYMLINK_MODE),
                self::blob('escape', strlen('../../etc/passwd'), DetectionFiles::SYMLINK_MODE),
                self::dir('empty'),
            ])),
            self::RAW . 'package.json' => Http::response($package),
            self::RAW . 'docker/compose.yml' => Http::response($compose),
            self::RAW . 'compose.yml' => Http::response('docker/compose.yml'),
            self::RAW . 'escape' => Http::response('../../etc/passwd'),
        ]);

        $meta = self::tree()->writeTo($this->dir, 'https://github.com/owner/repo');

        $this->assertSame(
            ['repository' => 'https://github.com/owner/repo', 'branch' => 'main', 'commit' => self::SHA],
            $meta
        );
        $this->assertSame($package, file_get_contents($this->dir . '/package.json'));
        $this->assertSame('', file_get_contents($this->dir . '/index.js'));
        $this->assertSame('docker/compose.yml', readlink($this->dir . '/compose.yml'));
        $this->assertSame($compose, file_get_contents($this->dir . '/compose.yml'));
        // A link out of the repository is not followed anywhere.
        $this->assertFalse(is_link($this->dir . '/escape'));
        $this->assertSame('', file_get_contents($this->dir . '/escape'));
        $this->assertDirectoryExists($this->dir . '/empty');
        // As in a checkout, for a Dockerfile that copies .git/.
        $this->assertDirectoryExists($this->dir . '/.git');
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/index.js'));
    }

    public function test_without_git_the_tree_and_files_are_read_by_the_branch_name(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/trees/HEAD*' => Http::response(self::treeBody([self::blob('go.mod', 3)])),
            'raw.githubusercontent.com/owner/repo/HEAD/go.mod' => Http::response('mod'),
        ]);
        $throttled = new GitHubTree(static function (): array {
            throw new TreeUnavailable('git ls-remote failed.');
        });

        $meta = $throttled->writeTo($this->dir, 'https://github.com/owner/repo');

        $this->assertNull($meta['commit']);
        $this->assertNull($meta['branch']);
        $this->assertSame('mod', file_get_contents($this->dir . '/go.mod'));
    }

    public function test_a_requested_branch_is_reported_as_asked(): void
    {
        Http::fake([self::TREE_URL => Http::response(self::treeBody([self::blob('index.html', 10)]))]);

        $meta = self::tree()->writeTo($this->dir, 'https://github.com/owner/repo', 'release');

        $this->assertSame('release', $meta['branch']);
    }

    /**
     * @return array<string, array{0: \Closure(): void}>
     */
    public static function unavailable(): array
    {
        $tree = static fn (array $entries, bool $truncated = false): \Closure =>
            static fn () => Http::fake([self::TREE_URL => Http::response(self::treeBody($entries, $truncated))]);

        return [
            'private or missing' => [static fn () => Http::fake([self::TREE_URL => Http::response(['message' => 'Not Found'], 404)])],
            'rate limited' => [static fn () => Http::fake([self::TREE_URL => Http::response(['message' => 'rate limit'], 403)])],
            'too many requests' => [static fn () => Http::fake([self::TREE_URL => Http::response([], 429)])],
            'truncated' => [$tree([self::blob('index.html', 10)], true)],
            'submodule' => [$tree([['path' => 'theme', 'mode' => '160000', 'type' => 'commit', 'sha' => 'x']])],
            'a path climbing out' => [$tree([self::blob('../outside', 1)])],
            'an absolute path' => [$tree([self::blob('/etc/passwd', 1)])],
            'too many files' => [$tree(array_map(
                static fn (int $i): array => self::blob("s{$i}/Dockerfile", 1),
                range(1, DetectionFiles::MAX_FILES + 1)
            ))],
            'a file too large' => [$tree([self::blob('package-lock.json', DetectionFiles::MAX_BYTES + 1)])],
            'a fetch that fails' => [static fn () => Http::fake([
                self::TREE_URL => Http::response(self::treeBody([self::blob('package.json', 2)])),
                self::RAW . 'package.json' => Http::response('', 404),
            ])],
            'a fetch cut short' => [static fn () => Http::fake([
                self::TREE_URL => Http::response(self::treeBody([self::blob('package.json', 20)])),
                self::RAW . 'package.json' => Http::response('{}'),
            ])],
        ];
    }

    #[DataProvider('unavailable')]
    public function test_anything_it_cannot_write_exactly_sends_the_inspection_to_a_clone(\Closure $fake): void
    {
        $fake();

        $this->expectException(TreeUnavailable::class);
        self::tree()->writeTo($this->dir, 'https://github.com/owner/repo');
    }

    public function test_a_repository_that_is_not_on_github_is_never_requested(): void
    {
        Http::fake();

        try {
            self::tree()->writeTo($this->dir, 'https://gitlab.com/owner/repo');
            $this->fail('A gitlab.com repository was read from a GitHub tree.');
        } catch (TreeUnavailable) {
            Http::assertNothingSent();
        }
    }

    public function test_go_sources_are_fetched_with_what_is_left_and_a_failure_there_is_not_fatal(): void
    {
        Http::fake([
            self::TREE_URL => Http::response(self::treeBody([
                self::blob('go.mod', 12),
                self::blob('main.go', 12),
                self::blob('cmd/tool/main.go', 12),
            ])),
            self::RAW . 'go.mod' => Http::response('module x/y\n'),
            self::RAW . 'main.go' => Http::response('package main'),
            self::RAW . 'cmd/tool/main.go' => Http::response('', 500),
        ]);

        self::tree()->writeTo($this->dir, 'https://github.com/owner/repo');

        $this->assertSame('package main', file_get_contents($this->dir . '/main.go'));
        $this->assertSame('', file_get_contents($this->dir . '/cmd/tool/main.go'));
    }
}
