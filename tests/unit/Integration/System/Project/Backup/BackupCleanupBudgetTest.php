<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\User;
use App\Support\JobBudget;
use App\Support\JobDeadline;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup\HomeFiles;
use App\System\Project\Backup\ProjectFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;

/**
 * A spent work window gives its steps one second, so the steps that undo or
 * finish the work run on the cleanup reserve the job keeps below its alarm.
 */
class BackupCleanupBudgetTest extends TestCase
{
    public function test_a_spent_deadline_still_gives_every_rollback_step_the_cleanup_reserve(): void
    {
        $system = $this->recordingSystem();
        [$files, $home] = $this->filesFor($system, new JobDeadline(microtime(true) - 5));
        $home->swap('.restore-1', '.pre-restore-1', ['.restore-1', '.pre-restore-1']);
        $system->hostTimeouts = [];
        $system->processTimeouts = [];

        $files->rollback('.pre-restore');
        $files->discardAside('.pre-restore');
        $home->discardAside('.pre-restore-1');
        $home->removeEntry('.restore-1');
        $home->removeEmptyAside('.pre-restore-1');
        $home->rollback('.restore-1', '.pre-restore-1');

        $this->assertNotEmpty($system->hostTimeouts);
        foreach ($system->hostTimeouts as $timeout) {
            $this->assertSame(JobBudget::CLEANUP_SECONDS, $timeout, 'host cleanup must run on the reserve');
        }
        $this->assertNotEmpty($system->processTimeouts);
        foreach ($system->processTimeouts as $timeout) {
            $this->assertSame(JobBudget::CLEANUP_SECONDS, $timeout, 'home rollback must run on the reserve');
        }
    }

    public function test_a_forward_tar_after_the_deadline_is_cut_to_a_second(): void
    {
        $system = $this->recordingSystem();
        [$files] = $this->filesFor($system, new JobDeadline(microtime(true) - 5));

        $files->archive('/tmp/files.tar.gz');

        $this->assertSame([1], $system->hostTimeouts);
    }

    private function recordingSystem(): System
    {
        return new class () extends System {
            /** @var list<int> */
            public array $hostTimeouts = [];

            /** @var list<int> */
            public array $processTimeouts = [];

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
                $this->hostTimeouts[] = $timeout;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->processTimeouts[] = $timeout;

                return new class (in_array('ls', (array) $cmd, true) ? "project\n" : '') extends Process {
                    public function __construct(private string $listing)
                    {
                        parent::__construct(['php', '-r', '']);
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }

                    public function getOutput(): string
                    {
                        return $this->listing;
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }
                };
            }
        };
    }

    /** @return array{ProjectFiles, HomeFiles} */
    private function filesFor(System $system, JobDeadline $deadline): array
    {
        $user = new User();
        $user->username = 'alice';
        $project = new ProjectAggregate($system, $user);

        return [new ProjectFiles($project, $deadline), new HomeFiles($project, $deadline)];
    }
}
