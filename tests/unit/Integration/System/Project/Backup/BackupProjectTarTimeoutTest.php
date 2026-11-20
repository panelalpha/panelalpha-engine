<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\User;
use App\Support\JobBudget;
use App\Support\JobDeadline;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup\ProjectFiles;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;

/**
 * `ProjectFiles::archive()` and `extract()` ran `tar`
 * through `execOnHost()`'s 600 s default, so a ~/project of tens of GB was
 * killed mid-tar. They must pass the job's long budget.
 *
 * That budget is shared with the other backup and restore steps, so a step is
 * capped at what is left of one deadline, not at the full window.
 */
class BackupProjectTarTimeoutTest extends TestCase
{
    public function test_the_project_tar_steps_use_the_job_budget_not_the_600s_default(): void
    {
        $system = $this->recordingSystem();
        $files = $this->projectFilesFor($system);

        $files->archive('/tmp/files.tar.gz');
        $files->extract('/tmp/files.tar.gz', 'project.incoming');

        $tars = $this->tarTimeouts($system);
        $this->assertCount(2, $tars, 'the archive step and the extract step both run tar');
        foreach ($tars as $timeout) {
            $this->assertLessThanOrEqual(JobBudget::COMMAND_SECONDS, $timeout);
            $this->assertGreaterThan(JobBudget::COMMAND_SECONDS - 60, $timeout, 'tar must not run on the 600 s default');
        }
    }

    public function test_a_shared_deadline_caps_a_project_tar_below_the_single_command_window(): void
    {
        $system = $this->recordingSystem();
        $files = $this->projectFilesFor($system, new JobDeadline(microtime(true) + 120));

        $files->archive('/tmp/files.tar.gz');

        $this->assertSame([120], $this->tarTimeouts($system));
    }

    private function recordingSystem(): System
    {
        return new class () extends System {
            /** @var list<array{0: list<string>, 1: int}> */
            public array $ran = [];

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
    }

    private function projectFilesFor(System $system, ?JobDeadline $deadline = null): ProjectFiles
    {
        $user = new User();
        $user->username = 'alice';
        $project = new ProjectAggregate($system, $user);

        return new ProjectFiles($project, $deadline ?? JobDeadline::startingNow());
    }

    /**
     * @return list<int>
     */
    private function tarTimeouts(object $system): array
    {
        return array_values(array_map(
            static fn (array $call): int => $call[1],
            array_filter($system->ran, static fn (array $call): bool => in_array('tar', $call[0], true)),
        ));
    }
}
