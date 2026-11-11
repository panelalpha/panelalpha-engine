<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\Support\JobDeadline;
use App\System\Project\Backup\ProjectFiles;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Putting the pre-restore tree back never leaves the account without a
 * `~/project`: the failed tree is renamed aside and deleted last.
 */
class BackupRollbackProjectTest extends TestCase
{
    private const HOME = '/home/alice';

    public function test_the_pre_restore_tree_is_back_before_the_failed_tree_is_deleted(): void
    {
        $system = $this->recordingSystem(existing: [self::HOME . '/project']);

        $this->projectFilesFor($system)->rollback('project.pre-restore');

        $this->assertSame([
            ['mv', '--', self::HOME . '/project', self::HOME . '/project.failed-restore'],
            ['mv', '--', self::HOME . '/project.pre-restore', self::HOME . '/project'],
            ['rm', '-rf', '--', self::HOME . '/project.failed-restore'],
        ], $system->ran);
    }

    public function test_a_delete_that_fails_still_leaves_the_pre_restore_tree_in_place(): void
    {
        Log::spy();
        $system = $this->recordingSystem(existing: [self::HOME . '/project'], failingDelete: true);

        $this->projectFilesFor($system)->rollback('project.pre-restore');

        $this->assertContains(['mv', '--', self::HOME . '/project.pre-restore', self::HOME . '/project'], $system->ran);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failed_tree_left_by_an_earlier_rollback_is_cleared_first(): void
    {
        $system = $this->recordingSystem(existing: [self::HOME . '/project', self::HOME . '/project.failed-restore']);

        $this->projectFilesFor($system)->rollback('project.pre-restore');

        $this->assertSame(['rm', '-rf', '--', self::HOME . '/project.failed-restore'], $system->ran[0]);
        $this->assertSame(['mv', '--', self::HOME . '/project', self::HOME . '/project.failed-restore'], $system->ran[1]);
    }

    /**
     * @param list<string> $existing paths `test -e` finds
     */
    private function recordingSystem(array $existing, bool $failingDelete = false): System
    {
        return new class ($existing, $failingDelete) extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            /** @param list<string> $existing */
            public function __construct(private array $existing, private bool $failingDelete)
            {
            }

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;

                return array_slice(array_values($argv), 0, 2) === [LocalRoot::CLIENT, '--host']
                    ? $this->execOnHost($argv, $env, $timeout)
                    : parent::exec($cmd, $env, $timeout);
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $argv = array_values((array) $cmd);
                $this->ran[] = $argv;
                if ($argv[0] === 'rm' && $this->failingDelete) {
                    throw new \RuntimeException('rm timed out');
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $found = in_array(end($cmd), $this->existing, true);

                return new class ($found) extends Process {
                    public function __construct(private bool $found)
                    {
                        parent::__construct(['php', '-r', '']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->found ? 0 : 1;
                    }
                };
            }
        };
    }

    private function projectFilesFor(System $system): ProjectFiles
    {
        $user = new User();
        $user->username = 'alice';
        $project = new class ($system, $user) extends ProjectAggregate {
            public function homeDirPath(): string
            {
                return '/home/alice';
            }
        };

        return new ProjectFiles($project, JobDeadline::startingNow());
    }
}
