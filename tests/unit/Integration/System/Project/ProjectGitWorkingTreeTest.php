<?php

namespace Tests\Integration\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestableProjectGit;

class ProjectGitWorkingTreeTest extends TestCase
{
    private string $tmpDir;

    /** @var list<string> */
    private array $remotes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/pa-git-working-tree-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0700, true);
        $this->runGit(['init']);
        $this->runGit(['branch', '-M', 'main']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->runGit(['config', 'user.name', 'Test User']);
        $this->runGit(['config', 'core.autocrlf', 'false']);
    }

    protected function tearDown(): void
    {
        foreach ($this->remotes as $remote) {
            $this->removeDir($remote);
        }
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function test_commits_returns_subject(): void
    {
        file_put_contents($this->tmpDir . '/hello.txt', "hello\n");
        $this->runGit(['add', 'hello.txt']);
        $this->runGit(['commit', '-m', 'Initial commit']);

        $commits = $this->makeGit()->commits(10);

        $this->assertNotEmpty($commits);
        $this->assertSame('Initial commit', $commits[0]['subject']);
    }

    public function test_commits_honours_branch_argument(): void
    {
        file_put_contents($this->tmpDir . '/hello.txt', "hello\n");
        $this->runGit(['add', 'hello.txt']);
        $this->runGit(['commit', '-m', 'On main']);

        $this->runGit(['checkout', '-b', 'feature']);
        file_put_contents($this->tmpDir . '/hello.txt', "feature\n");
        $this->runGit(['add', 'hello.txt']);
        $this->runGit(['commit', '-m', 'On feature']);

        $git = $this->makeGit();
        $mainCommits = $git->commits(10, 'main');
        $featureCommits = $git->commits(10, 'feature');

        $this->assertSame('On main', $mainCommits[0]['subject']);
        $this->assertSame('On feature', $featureCommits[0]['subject']);
    }

    public function test_branches_deduplicates_origin_tracking_ref(): void
    {
        file_put_contents($this->tmpDir . '/hello.txt', "hello\n");
        $this->runGit(['add', 'hello.txt']);
        $this->runGit(['commit', '-m', 'Initial commit']);
        $this->runGit(['remote', 'add', 'origin', $this->tmpDir]);
        $this->runGit(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
        $this->runGit(['update-ref', 'refs/remotes/origin/develop', 'HEAD']);
        $this->runGit(['branch', '--set-upstream-to=origin/main', 'main']);

        $branches = $this->makeGit()->branches();
        $byName = [];
        foreach ($branches as $branch) {
            $byName[$branch['name']] = $branch;
        }

        $this->assertEqualsCanonicalizing(['main', 'develop'], array_column($branches, 'name'));
        $this->assertTrue($byName['main']['current']);
        $this->assertSame('origin/main', $byName['main']['tracking']);
        $this->assertFalse($byName['develop']['current']);
        $this->assertSame('origin/develop', $byName['develop']['tracking']);
        $this->assertArrayNotHasKey('origin/main', $byName);
        $this->assertArrayNotHasKey('origin/develop', $byName);
    }

    public function test_revert_restores_dirty_file_to_head(): void
    {
        file_put_contents($this->tmpDir . '/hello.txt', "hello\n");
        $this->runGit(['add', 'hello.txt']);
        $this->runGit(['commit', '-m', 'Initial commit']);

        file_put_contents($this->tmpDir . '/hello.txt', "dirty\n");
        file_put_contents($this->tmpDir . '/untracked.txt', "new\n");

        $status = $this->makeGit()->revert();

        $this->assertFalse($status['dirty']);
        $this->assertStringEqualsStringIgnoringLineEndings("hello\n", (string) file_get_contents($this->tmpDir . '/hello.txt'));
        $this->assertFileDoesNotExist($this->tmpDir . '/untracked.txt');
    }

    /**
     * The branch list reads the remote, so every branch it offers can be listed: one the checkout never
     * fetched is fetched for the listing.
     */
    public function test_commits_fetches_a_branch_the_checkout_has_not_got(): void
    {
        $this->commit('On main');
        $this->runGit(['checkout', '-b', 'test']);
        $this->commit('On test');
        $this->runGit(['checkout', 'main']);
        $this->publish(['main', 'test']);
        $this->runGit(['branch', '-D', 'test']);
        $this->runGit(['update-ref', '-d', 'refs/remotes/origin/test']);

        $commits = $this->makeGit()->commits(10, 'test');

        $this->assertSame('On test', $commits[0]['subject']);
    }

    /** A branch the checkout fetched, but never checked out, is listed without asking the remote again. */
    public function test_commits_lists_a_fetched_branch_the_checkout_has_not_checked_out(): void
    {
        $this->commit('On main');
        $this->runGit(['checkout', '-b', 'test']);
        $this->commit('On test');
        $this->runGit(['checkout', 'main']);
        $remote = $this->publish(['main', 'test']);
        $this->runGit(['fetch', 'origin', 'refs/heads/test:refs/remotes/origin/test']);
        $this->runGit(['branch', '-D', 'test']);
        $this->removeDir($remote);
        $this->remotes = [];
        $this->runGit(['remote', 'set-url', 'origin', $remote.'-gone']);

        $commits = $this->makeGit()->commits(10, 'test');

        $this->assertSame('On test', $commits[0]['subject']);
    }

    public function test_commits_refuses_a_branch_the_remote_does_not_have(): void
    {
        $this->commit('On main');
        $this->publish(['main']);

        try {
            $this->makeGit()->commits(10, 'nope');
            $this->fail('A branch the remote does not have must be refused.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('git_branch_not_found', $e->problemCode);
            $this->assertSame("The remote has no branch named 'nope'. Its branches: main.", $e->getMessage());
        }
    }

    private function makeGit(): TestableProjectGit
    {
        $model = new ModelsUser();
        $model->username = 'tester';

        return new TestableProjectGit(
            new Project(new System(), $model),
            'public_html',
            $this->localExecute(),
            $this->tmpDir,
        );
    }

    /**
     * @return callable(list<string>, ?string, int): string
     */
    private function localExecute(): callable
    {
        return function (array $gitArgs, ?string $token, int $timeout = 600): string {
            $p = new Process($gitArgs);
            $p->setTimeout($timeout);
            $p->run();
            if (!$p->isSuccessful()) {
                throw new GitException($p->getErrorOutput() ?: $p->getOutput(), 400);
            }

            return $p->getOutput();
        };
    }

    /**
     * @param list<string> $args
     */
    private function runGit(array $args): void
    {
        ($this->localExecute())(['git', '-C', $this->tmpDir, ...$args], null);
    }

    /** A commit that adds a file named after its subject, so each branch lists its own. */
    private function commit(string $subject): void
    {
        file_put_contents($this->tmpDir . '/' . $subject . '.txt', $subject . "\n");
        $this->runGit(['add', '.']);
        $this->runGit(['commit', '-m', $subject]);
    }

    /**
     * A bare repository holding the local branches named, added as origin.
     *
     * @param list<string> $branches
     */
    private function publish(array $branches): string
    {
        $remote = sys_get_temp_dir() . '/pa-git-remote-' . bin2hex(random_bytes(8)) . '.git';
        $this->runGit(['init', '--bare', $remote]);
        $this->runGit(['remote', 'add', 'origin', $remote]);
        foreach ($branches as $branch) {
            $this->runGit(['push', 'origin', $branch . ':refs/heads/' . $branch]);
        }
        $this->remotes[] = $remote;

        return $remote;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
