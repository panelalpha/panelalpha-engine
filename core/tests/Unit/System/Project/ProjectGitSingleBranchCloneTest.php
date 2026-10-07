<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The deploy clones `--depth=1 --branch main`, which is single-branch: origin's
 * fetch refspec names main only. Changing to another branch, and pulling it
 * afterwards, must still reach origin/<branch>. Real git, real bare origin.
 */
class ProjectGitSingleBranchCloneTest extends TestCase
{
    private string $root;

    private string $origin;

    private string $author;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-git-single-' . bin2hex(random_bytes(8));
        $this->origin = $this->root . '/origin.git';
        $this->author = $this->root . '/author';
        $this->checkout = $this->root . '/checkout';
        mkdir($this->root, 0700, true);

        $this->git($this->root, ['init', '--bare', '-b', 'main', $this->origin]);
        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', $this->origin, $this->author]);
        $this->git($this->author, ['config', 'user.email', 'test@example.com']);
        $this->git($this->author, ['config', 'user.name', 'Test User']);
        $this->git($this->author, ['checkout', '-B', 'main']);
        $this->commit('index.php', "main\n");
        $this->git($this->author, ['push', 'origin', 'main']);
        $this->git($this->author, ['checkout', '-b', 'broken']);
        $this->commit('index.php', "broken v1\n");
        $this->git($this->author, ['push', 'origin', 'broken']);

        // file:// so --depth is honoured, as it is over https.
        $this->git($this->root, ['clone', '--depth=1', '--branch', 'main', 'file://' . $this->origin, $this->checkout]);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_change_branch_reaches_a_branch_the_clone_did_not_fetch(): void
    {
        $this->assertSame("+refs/heads/main:refs/remotes/origin/main\n", $this->git($this->checkout, ['config', '--get-all', 'remote.origin.fetch']));

        $status = $this->makeGit()->changeBranch('broken');

        $this->assertSame('broken', $status['branch']);
        $this->assertSame('origin/broken', $status['tracking']);
        $this->assertSame('broken v1', trim((string) file_get_contents($this->checkout . '/index.php')));
    }

    public function test_a_pull_after_the_change_takes_the_branchs_new_commits(): void
    {
        $this->makeGit()->changeBranch('broken');
        $this->commit('index.php', "broken v2\n");
        $this->git($this->author, ['push', 'origin', 'broken']);

        $this->makeGit('broken')->pull('force');

        $this->assertSame('broken v2', trim((string) file_get_contents($this->checkout . '/index.php')));
    }

    public function test_a_branch_the_remote_does_not_have_is_still_refused_and_not_added_to_the_fetch(): void
    {
        try {
            $this->makeGit()->changeBranch('nope');
            $this->fail('A missing branch was not refused');
        } catch (GitException) {
        }

        $this->assertSame("+refs/heads/main:refs/remotes/origin/main\n", $this->git($this->checkout, ['config', '--get-all', 'remote.origin.fetch']));
        $this->git($this->checkout, ['fetch', 'origin']);
    }

    private function makeGit(string $branch = 'main'): TestableProjectGit
    {
        $model = new ModelsUser();
        $model->username = 'tester';
        $model->putSiteGit('', ['repo_url' => 'file://' . $this->origin, 'branch' => $branch, 'token' => null]);

        return new TestableProjectGit(
            new Project(new System(), $model),
            'public_html',
            function (array $argv, ?string $token, int $timeout = 600): string {
                $p = new Process($argv);
                $p->setTimeout($timeout);
                $p->run();
                if (!$p->isSuccessful()) {
                    throw new GitException($p->getErrorOutput() ?: $p->getOutput(), 400);
                }

                return $p->getOutput();
            },
            $this->checkout,
        );
    }

    private function commit(string $file, string $contents): void
    {
        file_put_contents($this->author . '/' . $file, $contents);
        $this->git($this->author, ['add', '-A']);
        $this->git($this->author, ['commit', '-m', 'Change ' . $file]);
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $p = new Process(['git', ...$args], $cwd);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ': ' . ($p->getErrorOutput() ?: $p->getOutput()));
        }

        return $p->getOutput();
    }
}
