<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\User;
use App\Support\JobBudget;
use App\Support\JobDeadline;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup\HomeFiles;
use App\System\Project\Backup\ProjectFiles;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Root swaps a restored ~/project in beside the live one and puts the live one back on a rollback; a home is archived
 * whole, and an aside left from an earlier restore is only removed when it is empty.
 */
class ProjectFilesSwapTest extends TestCase
{
    /** @var list<list<string>> */
    public array $ran = [];

    /** @var list<int> */
    public array $timeouts = [];

    /** @var list<string> */
    public array $existing = [];

    public ?string $failing = null;

    /** @var array<string, string> the command that fails next, once the key has failed */
    public array $failingAfter = [];

    public function test_the_restored_tree_replaces_the_live_one_which_moves_aside(): void
    {
        $this->projectFiles()->swap('project.incoming', 'project.pre-restore');

        $this->assertSame([
            ['mv', '--', '/home/alice/project', '/home/alice/project.pre-restore'],
            ['mv', '--', '/home/alice/project.incoming', '/home/alice/project'],
        ], $this->ran);
    }

    public function test_an_aside_already_there_is_never_overwritten(): void
    {
        $this->existing = ['/home/alice/project.pre-restore'];

        try {
            $this->projectFiles()->swap('project.incoming', 'project.pre-restore');
            $this->fail('the swap must not overwrite an aside');
        } catch (\RuntimeException $e) {
            $this->assertSame('Pre-restore aside already exists at /home/alice/project.pre-restore; refusing to overwrite', $e->getMessage());
        }

        $this->assertSame([], $this->ran);
    }

    public function test_a_rollback_replaces_an_earlier_failed_tree_and_brings_the_aside_back(): void
    {
        $this->existing = ['/home/alice/project', '/home/alice/project.failed-restore'];

        $this->projectFiles()->rollback('project.pre-restore');

        $this->assertSame([
            ['rm', '-rf', '--', '/home/alice/project.failed-restore'],
            ['mv', '--', '/home/alice/project', '/home/alice/project.failed-restore'],
            ['mv', '--', '/home/alice/project.pre-restore', '/home/alice/project'],
            ['rm', '-rf', '--', '/home/alice/project.failed-restore'],
        ], $this->ran);
        $this->assertSame(array_fill(0, 4, JobBudget::CLEANUP_SECONDS), $this->timeouts);
    }

    public function test_a_rollback_without_a_live_tree_only_brings_the_aside_back(): void
    {
        $this->projectFiles()->rollback('project.pre-restore');

        $this->assertSame([
            ['mv', '--', '/home/alice/project.pre-restore', '/home/alice/project'],
            ['rm', '-rf', '--', '/home/alice/project.failed-restore'],
        ], $this->ran);
    }

    public function test_a_stage_that_cannot_be_removed_after_a_failed_extraction_is_only_logged(): void
    {
        Log::spy();
        $this->failing = 'rm';

        try {
            $this->projectFiles()->extract('/tmp/files.tar.gz', 'project.incoming');
            $this->fail('the extraction should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('rm failed', $e->getMessage());
        }

        $this->assertSame([['rm', '-rf', '--', '/home/alice/project.incoming', '/home/.panelalpha-restore-stage/alice']], $this->ran);
    }

    public function test_a_stage_whose_extraction_fails_and_will_not_go_is_logged(): void
    {
        Log::spy();
        $this->failing = 'tar';
        $this->failingAfter = ['tar' => 'rm'];

        try {
            $this->projectFiles()->extract('/tmp/files.tar.gz', 'project.incoming');
            $this->fail('the extraction should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('tar failed', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('Backup could not remove the restore stage', [
            'path' => '/home/.panelalpha-restore-stage/alice',
            'error' => 'rm failed',
        ]);
    }

    public function test_a_home_is_archived_whole_without_the_push_and_restore_scratch(): void
    {
        $this->homeFiles()->archive('/var/tmp/home.tar.gz');

        $this->assertCount(1, $this->ran);
        $this->assertSame('tar', $this->ran[0][0]);
        $this->assertContains('/var/tmp/home.tar.gz', $this->ran[0]);
        $this->assertContains('/home/alice', $this->ran[0]);
        $this->assertStringContainsString('./.incoming', implode(' ', $this->ran[0]));
        $this->assertStringContainsString('./.pre-push', implode(' ', $this->ran[0]));
    }

    public function test_no_aside_means_nothing_to_remove(): void
    {
        $this->homeFiles()->removeEmptyAside('.pre-restore');

        $this->assertSame([], $this->ran);
    }

    private function projectFiles(): ProjectFiles
    {
        return new ProjectFiles(new ProjectAggregate($this->system(), $this->user()), JobDeadline::startingNow());
    }

    private function homeFiles(): HomeFiles
    {
        return new HomeFiles(new ProjectAggregate($this->system(), $this->user()), JobDeadline::startingNow());
    }

    private function user(): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->details = ['UID' => 1002, 'GID' => 1003];

        return $user;
    }

    private function system(): System
    {
        $test = $this;

        return new class ($test) extends System {
            public function __construct(private readonly ProjectFilesSwapTest $test) {}

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;

                return array_slice(array_values($argv), 0, 2) === [LocalRoot::CLIENT, '--host']
                    ? $this->execOnHost($argv, $env, $timeout)
                    : '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) HostCommand::argv($cmd);
                $this->test->ran[] = $argv;
                $this->test->timeouts[] = $timeout;
                if ($this->test->failing === $argv[0]) {
                    $this->test->failing = $this->test->failingAfter[$argv[0]] ?? $this->test->failing;
                    throw new \RuntimeException($argv[0].' failed');
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $path = is_array($cmd) ? (string) (LocalRoot::values($cmd)[0] ?? '') : '';

                return in_array($path, $this->test->existing, true) ? FakeProcess::ok() : FakeProcess::failed('', 1);
            }
        };
    }
}
