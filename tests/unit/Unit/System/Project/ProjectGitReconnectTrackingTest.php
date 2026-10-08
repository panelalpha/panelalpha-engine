<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Disconnect removes origin, and git drops the branch's upstream with it.
 * Connecting the same checkout again must give the branch its upstream back,
 * against a real bare origin, because what is under test is git's own config.
 */
class ProjectGitReconnectTrackingTest extends TestCase
{
    private string $root;

    private string $origin;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-git-reconnect-' . bin2hex(random_bytes(8));
        $this->origin = $this->root . '/origin.git';
        $this->checkout = $this->root . '/checkout';
        mkdir($this->root, 0700, true);

        $this->git($this->root, ['init', '--bare', '-b', 'main', $this->origin]);
        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', $this->origin, $this->checkout]);
        $this->git($this->checkout, ['config', 'user.email', 'test@example.com']);
        $this->git($this->checkout, ['config', 'user.name', 'Test User']);
        $this->git($this->checkout, ['checkout', '-B', 'main']);
        file_put_contents($this->checkout . '/index.php', "v1\n");
        $this->git($this->checkout, ['add', '-A']);
        $this->git($this->checkout, ['commit', '-m', 'Initial commit']);
        $this->git($this->checkout, ['push', '-u', 'origin', 'main']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_reconnecting_on_the_same_branch_tracks_origin_again(): void
    {
        $model = new ModelsUser();
        $model->username = 'tester';
        $model->putSiteGit('', ['repo_url' => $this->origin, 'branch' => 'main', 'token' => null]);

        $this->assertSame('origin/main', $this->makeGit($model)->status()['tracking']);

        $this->makeGit($model)->disconnect();
        $this->makeGit($model)->connect($this->origin, 'main', null);
        $status = $this->makeGit($model)->status(true);

        $this->assertSame('origin/main', $status['tracking']);
        $this->assertSame(0, $status['commits_ahead']);
        $this->assertSame(0, $status['commits_behind']);
    }

    public function test_an_upstream_the_checkout_already_has_is_kept(): void
    {
        $this->git($this->checkout, ['remote', 'add', 'mirror', $this->origin]);
        $this->git($this->checkout, ['fetch', 'mirror']);
        $this->git($this->checkout, ['remote', 'remove', 'origin']);
        $this->git($this->checkout, ['branch', '--set-upstream-to=mirror/main', 'main']);
        $model = new ModelsUser();
        $model->username = 'tester';

        $this->makeGit($model)->connect($this->origin, 'main', null);

        $this->assertSame('mirror/main', $this->makeGit($model)->status()['tracking']);
    }

    private function makeGit(ModelsUser $model): TestableProjectGit
    {
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
