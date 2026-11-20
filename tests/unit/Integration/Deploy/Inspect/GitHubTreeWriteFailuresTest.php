<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\GitHubTree;
use App\Lib\Deploy\Inspect\ResolvedSource;
use App\Lib\Deploy\Inspect\TreeUnavailable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each place the written copy of a GitHub tree can fail, the file list GitHub may leave out, and the
 * ls-remote that names the commit, run against a git on PATH that answers as told.
 */
class GitHubTreeWriteFailuresTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $dir = '';

    private ?string $path = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/gh-tree-fail-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            putenv('PATH='.$this->path);
        }
        ResolvedSource::removeTree($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array{path: string, type: string}  ...$entries
     */
    private function skeletonFails(string $message, array ...$entries): void
    {
        $listed = array_map(static fn (array $e): array => $e + ['mode' => '', 'size' => null], $entries);

        try {
            GitHubTree::skeleton($this->dir.'/repo', $listed);
            $this->fail('The skeleton was written');
        } catch (TreeUnavailable $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function fakeGit(string $script): void
    {
        mkdir($this->dir.'/bin', 0700, true);
        file_put_contents($this->dir.'/bin/git', "#!/bin/sh\n".$script."\n");
        chmod($this->dir.'/bin/git', 0755);
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->dir.'/bin:'.$this->path);
    }

    public function test_a_workspace_that_is_a_file_cannot_take_a_skeleton(): void
    {
        file_put_contents($this->dir.'/repo', 'a file');

        $this->skeletonFails('Could not create the inspection workspace.');
    }

    public function test_a_directory_below_a_file_is_refused(): void
    {
        $this->skeletonFails('Could not create a/b.', ['path' => 'a', 'type' => 'blob'], ['path' => 'a/b', 'type' => 'tree']);
    }

    public function test_a_file_below_a_file_is_refused(): void
    {
        $this->skeletonFails('Could not create a/c.', ['path' => 'a', 'type' => 'blob'], ['path' => 'a/c', 'type' => 'blob']);
    }

    public function test_a_file_where_a_directory_is_is_refused(): void
    {
        $this->skeletonFails('Could not create x.', ['path' => 'x', 'type' => 'tree'], ['path' => 'x', 'type' => 'blob']);
    }

    public function test_an_answer_without_a_file_list_is_unavailable(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'odd'], 200)]);
        $tree = new GitHubTree(static fn (string $url, ?string $branch): array => [self::SHA, 'main']);

        $this->expectExceptionObject(new TreeUnavailable('GitHub returned no file list.'));

        $tree->writeTo($this->dir.'/repo', 'https://github.com/owner/repo');
    }

    public function test_a_link_out_of_the_repository_stays_an_empty_file(): void
    {
        file_put_contents($this->dir.'/escape', '');

        GitHubTree::placeLink($this->dir, 'escape', '../../etc/passwd');

        $this->assertFalse(is_link($this->dir.'/escape'));
        $this->assertFileExists($this->dir.'/escape');
    }

    public function test_an_empty_absolute_or_nul_target_lands_nowhere(): void
    {
        $this->assertNull(GitHubTree::resolveLink('docs/link', ''));
        $this->assertNull(GitHubTree::resolveLink('docs/link', '/etc/passwd'));
        $this->assertNull(GitHubTree::resolveLink('docs/link', "a\0b"));
        $this->assertSame('docs/guide.md', GitHubTree::resolveLink('docs/link', './guide.md'));
    }

    public function test_a_link_whose_placeholder_is_gone_cannot_be_written(): void
    {
        $this->expectExceptionObject(new TreeUnavailable('Could not write missing.'));

        GitHubTree::placeLink($this->dir, 'missing', 'target');
    }

    public function test_ls_remote_names_the_commit_and_the_default_branch(): void
    {
        $this->fakeGit("printf 'ref: refs/heads/trunk\\tHEAD\\n".self::SHA."\\tHEAD\\n'");

        $this->assertSame([self::SHA, 'trunk'], GitHubTree::lsRemote('https://github.com/owner/repo', null));
    }

    public function test_ls_remote_that_git_refuses_is_unavailable(): void
    {
        $this->fakeGit('echo "fatal: could not read Username" >&2; exit 128');

        $this->expectExceptionObject(new TreeUnavailable('git ls-remote failed.'));

        GitHubTree::lsRemote('https://github.com/owner/repo', 'main');
    }

    public function test_ls_remote_that_outlives_its_timeout_is_unavailable(): void
    {
        $this->fakeGit('sleep 5');

        try {
            GitHubTree::lsRemote('https://github.com/owner/repo', null, 0.5);
            $this->fail('A hung ls-remote returned');
        } catch (TreeUnavailable $e) {
            $this->assertStringStartsWith('git ls-remote failed: ', $e->getMessage());
        }
    }
}
