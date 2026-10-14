<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use App\Support\JobBudget;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup;
use PHPUnit\Framework\TestCase;

/**
 * engine#791: `Backup::tarProject()` and `extractProjectArchive()` ran `tar`
 * through `execOnHost()`'s 600 s default, so a ~/project of tens of GB was
 * killed mid-tar. They must pass the job's long budget.
 */
class BackupProjectTarTimeoutTest extends TestCase
{
    public function test_the_project_tar_steps_use_the_job_budget_not_the_600s_default(): void
    {
        $system = new class () extends System {
            /** @var list<array{0: list<string>, 1: int}> */
            public array $ran = [];

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = [(array) $cmd, $timeout];

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                return new class () extends \Symfony\Component\Process\Process {
                    public function __construct()
                    {
                        parent::__construct(['php', '-r', '']);
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }
                };
            }
        };

        $user = new User();
        $user->username = 'alice';
        $project = new ProjectAggregate($system, $user);
        $backup = new class ($project, new BackupRecord()) extends Backup {
            public function tarForTest(string $dest): void
            {
                $this->tarProject($dest);
            }

            public function extractForTest(string $archivePath, string $incomingDir): void
            {
                $this->extractProjectArchive($archivePath, $incomingDir);
            }
        };

        $backup->tarForTest('/tmp/files.tar.gz');
        $backup->extractForTest('/tmp/files.tar.gz', 'project.incoming');

        $tars = array_values(array_filter(
            $system->ran,
            static fn (array $call): bool => in_array('tar', $call[0], true)
        ));
        $this->assertCount(2, $tars, 'the archive step and the extract step both run tar');
        foreach ($tars as [$cmd, $timeout]) {
            $this->assertSame(JobBudget::COMMAND_SECONDS, $timeout, 'tar must not run on the 600 s default');
        }
    }
}
