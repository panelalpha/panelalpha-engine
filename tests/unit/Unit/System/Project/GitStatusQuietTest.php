<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\WorkTree;
use PHPUnit\Framework\TestCase;

/**
 * Git reads run quietly: on a project still waiting for its files the deploy
 * log is open, and git's "not a git repository" is no part of it.
 */
class GitStatusQuietTest extends TestCase
{
    public function test_a_status_read_of_a_directory_without_a_repository_runs_every_command_quietly(): void
    {
        $git = new QuietRecordingWorkTree(repositoryExists: false);

        $status = $git->status();

        $this->assertFalse($status['repository_exists']);
        $this->assertNotEmpty($git->quiet, 'status ran no git command');
        $this->assertSame([], $git->logged, 'a status read streamed git into the deploy log');
    }

    public function test_branch_commit_and_revert_calls_without_a_repository_log_no_git_error(): void
    {
        foreach (['branches' => [], 'commits' => [10, null], 'revert' => [null]] as $method => $arguments) {
            $git = new QuietRecordingWorkTree(repositoryExists: false);
            try {
                $git->{$method}(...$arguments);
                $this->fail("{$method} answered without a repository");
            } catch (GitException $e) {
                $this->assertSame(422, $e->httpStatus, $method);
            }
            $this->assertSame([], $git->logged, "{$method} streamed git into the deploy log");
        }
    }

    public function test_a_command_after_a_status_read_is_logged_again(): void
    {
        $git = new QuietRecordingWorkTree(repositoryExists: false);

        $git->status();
        $git->readHeadCommit();

        $this->assertNotSame([], $git->logged, 'the quiet mode outlived the status read');
    }
}

/** @internal Answers git as a directory with no repository would, recording how each command ran. */
final class QuietRecordingWorkTree extends WorkTree
{
    /** @var list<list<string>> */
    public array $quiet = [];

    /** @var list<list<string>> */
    public array $logged = [];

    public function __construct(private bool $repositoryExists)
    {
        $this->absolutePath = sys_get_temp_dir() . '/pa-git-quiet-' . bin2hex(random_bytes(6)) . '/project';
        $this->pathKey = 'project';
    }

    protected function user(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';

        return $model;
    }

    protected function system(): EngineSystem
    {
        return new class extends EngineSystem {
            public function __construct()
            {
            }
        };
    }

    protected function homeDirPath(): string
    {
        return dirname($this->absolutePath);
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        $this->logged[] = $cmd;

        return $this->answer();
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        $this->quiet[] = $cmd;

        return $this->answer();
    }

    private function answer(): string
    {
        if (!$this->repositoryExists) {
            throw new \RuntimeException('fatal: not a git repository (or any parent up to mount point /home)');
        }

        return '';
    }
}
