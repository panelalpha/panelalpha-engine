<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A pull whose new commit moves a checked-out submodule to a commit its
 * repository will not serve must still move the checkout: the deploy fetches
 * the submodules afterwards and keeps the one it cannot fetch. Real git.
 */
class ProjectGitSubmodulePullTest extends TestCase
{
    private string $root;

    private string $author;

    private string $checkout;

    private ?string $home = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-git-subpull-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/home', 0700, true);
        file_put_contents($this->root . '/home/.gitconfig', "[protocol \"file\"]\n\tallow = always\n[user]\n\tname = t\n\temail = t@example.com\n");
        $this->home = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->root . '/home');
        $_SERVER['HOME'] = $_ENV['HOME'] = $this->root . '/home';

        $this->git($this->root, ['init', '--bare', '-b', 'main', 'lib.git']);
        $this->git($this->root, ['clone', 'lib.git', 'lib']);
        file_put_contents($this->root . '/lib/a.txt', "lib\n");
        $this->git($this->root . '/lib', ['add', '-A']);
        $this->git($this->root . '/lib', ['commit', '-m', 'lib']);
        $this->git($this->root . '/lib', ['push', 'origin', 'HEAD:main']);

        $this->git($this->root, ['init', '--bare', '-b', 'main', 'origin.git']);
        $this->git($this->root, ['clone', 'origin.git', 'author']);
        $this->author = $this->root . '/author';
        $this->git($this->author, ['checkout', '-B', 'main']);
        $this->git($this->author, ['submodule', 'add', 'file://' . $this->root . '/lib.git', 'lib']);
        $this->git($this->author, ['commit', '-m', 'add lib']);
        $this->git($this->author, ['push', 'origin', 'main']);

        $this->checkout = $this->root . '/checkout';
        $this->git($this->root, ['clone', '--branch', 'main', 'file://' . $this->root . '/origin.git', $this->checkout]);
        $this->git($this->checkout, ['submodule', 'update', '--init']);
    }

    protected function tearDown(): void
    {
        if ($this->home !== null) {
            putenv('HOME=' . $this->home);
            $_SERVER['HOME'] = $_ENV['HOME'] = $this->home;
        }
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_a_pull_moving_a_submodule_to_an_unfetchable_commit_moves_the_checkout_and_keeps_the_submodule(): void
    {
        file_put_contents($this->author . '/index.php', "v2\n");
        $this->git($this->author, ['add', 'index.php']);
        $this->git($this->author, ['update-index', '--cacheinfo', '160000,' . str_repeat('2', 40) . ',lib']);
        $this->git($this->author, ['commit', '-m', 'move lib']);
        $this->git($this->author, ['push', 'origin', 'main']);

        $this->makeGit()->pull('force');

        $this->assertSame("v2\n", file_get_contents($this->checkout . '/index.php'));
        $this->assertSame("lib\n", file_get_contents($this->checkout . '/lib/a.txt'), 'the submodule keeps its checkout');
    }

    private function makeGit(): TestableProjectGit
    {
        $model = new ModelsUser();
        $model->username = 'tester';
        $model->putSiteGit('', ['repo_url' => 'file://' . $this->root . '/origin.git', 'branch' => 'main', 'token' => null]);

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
