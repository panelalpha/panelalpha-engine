<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A branch change is a checkout of the other branch in the tree already there: a file only the
 * previous branch had goes, the new branch's own content arrives, and a file the engine or the app
 * wrote and git ignores stays. A real repository with a real local remote.
 */
class GitBranchChangeTest extends TestCase
{
    private string $root;

    private string $origin;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-branch-change-'.bin2hex(random_bytes(6));
        $this->origin = $this->root.'/origin.git';
        $this->checkout = $this->root.'/home/alice/project';
        mkdir($this->checkout, 0777, true);
        $this->withTwoBranchesOnARemote();
        $this->cloneOnTheReleaseBranch();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_a_file_only_the_previous_branch_had_is_gone_after_the_change(): void
    {
        $status = $this->git()->changeBranch('main');

        $this->assertSame('main', $status['branch']);
        $this->assertFileDoesNotExist($this->checkout.'/only-release.txt', 'the previous branch\'s file is still there');
        $this->assertSame("main\n", file_get_contents($this->checkout.'/index.html'), 'the new branch\'s own content did not arrive');
        $this->assertFileExists($this->checkout.'/.env', 'an ignored file the engine wrote did not survive');
        $this->assertSame([], $this->untracked(), 'the change left the checkout dirty');
    }

    public function test_switching_back_brings_the_other_branchs_files_and_keeps_the_apps_own(): void
    {
        $git = $this->git();
        $git->changeBranch('main');

        $status = $git->changeBranch('release');

        $this->assertSame('release', $status['branch']);
        $this->assertFileExists($this->checkout.'/only-release.txt');
        $this->assertSame("release\n", file_get_contents($this->checkout.'/index.html'));
        $this->assertFileExists($this->checkout.'/.env');
    }

    public function test_an_untracked_file_git_does_not_ignore_refuses_the_change(): void
    {
        $this->write($this->checkout.'/upload.zip', "not the repository's\n");

        try {
            $this->git()->changeBranch('main');
            $this->fail('A change over an untracked file must be refused.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Working tree is dirty.', $e->getMessage());
        }

        $this->assertSame('release', trim($this->command(['git', '-C', $this->checkout, 'branch', '--show-current'])));
        $this->assertFileExists($this->checkout.'/only-release.txt');
        $this->assertFileExists($this->checkout.'/upload.zip');
    }

    private function withTwoBranchesOnARemote(): void
    {
        $seed = $this->root.'/seed';
        mkdir($seed, 0777, true);
        $this->command(['git', 'init', '--quiet', '--initial-branch=main', $seed]);
        $this->write($seed.'/index.html', "main\n");
        $this->commit($seed, 'main');

        $this->command(['git', 'init', '--quiet', '--bare', '--initial-branch=main', $this->origin]);
        $this->command(['git', '-C', $seed, 'remote', 'add', 'origin', $this->origin]);
        $this->command(['git', '-C', $seed, 'push', '--quiet', 'origin', 'main']);

        $this->command(['git', '-C', $seed, 'checkout', '--quiet', '-b', 'release']);
        $this->write($seed.'/index.html', "release\n");
        $this->write($seed.'/only-release.txt', "release only\n");
        $this->commit($seed, 'release');
        $this->command(['git', '-C', $seed, 'push', '--quiet', 'origin', 'release']);
        $this->command(['git', '-C', $seed, 'checkout', '--quiet', 'main']);
    }

    private function cloneOnTheReleaseBranch(): void
    {
        $this->command(['git', 'clone', '--quiet', $this->origin, $this->checkout]);
        $this->command(['git', '-C', $this->checkout, 'checkout', '--quiet', 'release']);
        $this->write($this->checkout.'/.env', "APP_ENV=production\n");
        file_put_contents($this->checkout.'/.git/info/exclude', "/.env\n", FILE_APPEND);
    }

    private function git(): TestableProjectGit
    {
        $execute = fn (array $command, ?string $token, int $timeout): string => $this->command($command, $timeout);

        return new TestableProjectGit(new Project($this->system(), $this->deployManagedUser()), 'project', $execute);
    }

    private function deployManagedUser(): ModelsUser
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'git_repo' => $this->origin,
            'git_branch' => 'release',
        ]);

        return $model;
    }

    private function system(): System
    {
        $root = $this->root;

        return new class($root) extends System
        {
            public function __construct(private string $root) {}

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }
        };
    }

    /** @return list<string> */
    private function untracked(): array
    {
        $status = trim($this->command(['git', '-C', $this->checkout, 'status', '--porcelain']));

        return $status === '' ? [] : explode("\n", $status);
    }

    /**
     * @param  list<string>  $command
     */
    private function command(array $command, int $timeout = 60): string
    {
        $process = new Process($command, $this->root, ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new GitException(trim($process->getErrorOutput() ?: $process->getOutput()), 400);
        }

        return $process->getOutput();
    }

    private function commit(string $workTree, string $message): void
    {
        $this->command(['git', '-C', $workTree, 'add', '--all']);
        $this->command([
            'git', '-C', $workTree,
            '-c', 'user.name=Alice', '-c', 'user.email=alice@example.com',
            'commit', '--quiet', '--message', $message,
        ]);
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
