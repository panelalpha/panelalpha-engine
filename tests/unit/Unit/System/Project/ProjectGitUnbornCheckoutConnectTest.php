<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A connect that fails leaves a `.git` with no commit and a disconnect keeps it, so the retry connects onto an empty
 * checkout. It must fetch the branch like a fresh one; a checkout that has commits keeps its files untouched.
 */
class ProjectGitUnbornCheckoutConnectTest extends TestCase
{
    private string $root;

    private string $origin;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-git-unborn-' . bin2hex(random_bytes(8));
        $this->origin = $this->root . '/origin.git';
        $this->site = $this->root . '/site';
        mkdir($this->root, 0700, true);

        $this->git($this->root, ['init', '--bare', '-b', 'main', $this->origin]);
        $seed = $this->root . '/seed';
        $this->git($this->root, ['clone', $this->origin, $seed]);
        $this->git($seed, ['config', 'user.email', 'test@example.com']);
        $this->git($seed, ['config', 'user.name', 'Test User']);
        $this->git($seed, ['checkout', '-B', 'main']);
        file_put_contents($seed . '/index.html', "from the repository\n");
        file_put_contents($seed . '/app.php', "<?php\n");
        $this->git($seed, ['add', '-A']);
        $this->git($seed, ['commit', '-m', 'Initial commit']);
        $this->git($seed, ['push', '-u', 'origin', 'main']);

        mkdir($this->site, 0700, true);
        file_put_contents($this->site . '/index.html', "the site's own page\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_connecting_a_checkout_with_no_commit_fetches_the_branch(): void
    {
        $this->git($this->site, ['init']);
        $this->git($this->site, ['symbolic-ref', 'HEAD', 'refs/heads/main']);

        $status = $this->makeGit($this->user())->connect($this->origin, 'main', null);

        $this->assertTrue($status['connected']);
        $this->assertArrayNotHasKey('sync_error', $status);
        $this->assertFalse($status['dirty']);
        $this->assertSame('origin/main', $status['tracking']);
        $this->assertSame("from the repository\n", file_get_contents($this->site . '/index.html'));
        $this->assertFileExists($this->site . '/app.php');
        $this->assertSame($this->originHead(), $this->git($this->site, ['rev-parse', 'HEAD']));
    }

    public function test_an_unrelated_file_in_the_site_is_left_alone(): void
    {
        $this->git($this->site, ['init']);
        $this->git($this->site, ['symbolic-ref', 'HEAD', 'refs/heads/main']);
        file_put_contents($this->site . '/notes.txt', "keep me\n");

        $this->makeGit($this->user())->connect($this->origin, 'main', null);

        $this->assertSame("from the repository\n", file_get_contents($this->site . '/index.html'));
        $this->assertSame("keep me\n", file_get_contents($this->site . '/notes.txt'));
    }

    public function test_a_left_over_origin_does_not_stop_the_fetch(): void
    {
        $this->git($this->site, ['init']);
        $this->git($this->site, ['symbolic-ref', 'HEAD', 'refs/heads/main']);
        $this->git($this->site, ['remote', 'add', 'origin', $this->origin]);

        $status = $this->makeGit($this->user())->connect($this->origin, 'main', null);

        $this->assertArrayNotHasKey('sync_error', $status);
        $this->assertSame("from the repository\n", file_get_contents($this->site . '/index.html'));
        $this->assertSame($this->originHead(), $this->git($this->site, ['rev-parse', 'HEAD']));
    }

    public function test_a_failed_fetch_of_the_unborn_checkout_says_so(): void
    {
        $this->git($this->site, ['init']);
        $this->git($this->site, ['symbolic-ref', 'HEAD', 'refs/heads/main']);
        $gone = $this->root . '/gone.git';
        $this->git($this->root, ['init', '--bare', '-b', 'main', $gone]);

        $status = $this->makeGit($this->user())->connect($gone, 'main', null);

        $this->assertStringStartsWith('Connected, but fetching main failed', $status['sync_error'] ?? '');
        $this->assertSame("the site's own page\n", file_get_contents($this->site . '/index.html'));
    }

    public function test_a_checkout_that_has_commits_keeps_its_files(): void
    {
        $this->git($this->root, ['clone', $this->origin, $this->site . '2']);
        $site = $this->site . '2';
        $this->git($site, ['config', 'user.email', 'test@example.com']);
        $this->git($site, ['config', 'user.name', 'Test User']);
        file_put_contents($site . '/index.html', "the site's own page\n");
        $this->git($site, ['commit', '-am', 'Local change']);
        $head = $this->git($site, ['rev-parse', 'HEAD']);

        $status = $this->makeGit($this->user(), $site)->connect($this->origin, 'main', null);

        $this->assertTrue($status['connected']);
        $this->assertSame("the site's own page\n", file_get_contents($site . '/index.html'));
        $this->assertSame($head, $this->git($site, ['rev-parse', 'HEAD']));
    }

    private function user(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'tester';

        return $model;
    }

    private function originHead(): string
    {
        return $this->git($this->root, ['--git-dir=' . $this->origin, 'rev-parse', 'main']);
    }

    private function makeGit(ModelsUser $model, ?string $path = null): TestableProjectGit
    {
        $path ??= $this->site;

        return new TestableProjectGit(
            new Project(new System(), $model),
            'public_html',
            function (array $argv, ?string $token, int $timeout = 600): string {
                $p = new Process($argv);
                $p->setTimeout($timeout);
                $p->run();
                if (! $p->isSuccessful()) {
                    throw new GitException($p->getErrorOutput() ?: $p->getOutput(), 400);
                }

                return $p->getOutput();
            },
            $path,
        );
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $p = new Process(['git', ...$args], $cwd);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ': ' . ($p->getErrorOutput() ?: $p->getOutput()));
        }

        return trim($p->getOutput());
    }
}
