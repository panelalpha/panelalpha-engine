<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild re-clones ~/project. The running app may only be stopped, and
 * ~/project emptied, once the new source has actually been fetched.
 */
class DindGitRepositoryRecloneTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    public function test_a_failed_clone_leaves_the_app_running_and_project_untouched(): void
    {
        $runner = new FakeGitRunner;
        $runner->failIfContains = ['clone'];
        $git = new TestableGitRepository($this->dind(), $runner);

        try {
            $git->cloneConfiguredRepository(function (): void {
                $this->events[] = 'stop app';
            });
            $this->fail('A failed clone must throw.');
        } catch (\RuntimeException) {
        }

        $this->assertNotContains('stop app', $this->events);
        foreach ($this->events as $event) {
            $this->assertStringNotContainsString('/home/alice/project', $event, 'nothing may touch ~/project');
        }
        $this->assertNotEmpty(
            array_filter(
                $this->events,
                static fn (string $event): bool => str_starts_with($event, 'sudo rm -rf /home/alice/.project-next-')
            ),
            'the staging clone is removed'
        );
    }

    public function test_a_successful_clone_stops_the_app_then_replaces_the_project(): void
    {
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
        ];
        $git = new TestableGitRepository($this->dind(), $runner);

        $git->cloneConfiguredRepository(function (): void {
            $this->events[] = 'stop app';
        });

        $clone = implode(' ', $runner->commands[0]);
        $this->assertStringContainsString('clone --depth=1', $clone);
        $this->assertMatchesRegularExpression('#/home/alice/\.project-next-[0-9a-f]+$#', $clone);

        $stop = array_search('stop app', $this->events, true);
        $clear = $this->indexOf('sudo find /home/alice/project -mindepth 1 -maxdepth 1 -exec rm -rf');
        $move = $this->indexOf('sudo find /home/alice/.project-next');
        $this->assertNotFalse($stop);
        $this->assertLessThan($clear, $stop, 'the app is stopped before ~/project is emptied');
        $this->assertLessThan($move, $clear);
    }

    public function test_each_rebuild_clones_into_its_own_staging_directory(): void
    {
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
        ];
        $git = new TestableGitRepository($this->dind(), $runner);

        $git->cloneConfiguredRepository(static function (): void {});
        $git->cloneConfiguredRepository(static function (): void {});

        $targets = [];
        foreach ($runner->commands as $command) {
            if (in_array('clone', $command, true)) {
                $targets[] = end($command);
            }
        }

        $this->assertCount(2, $targets);
        $this->assertNotSame($targets[0], $targets[1], 'a rebuild must not reuse a previous run\'s staging directory');
    }

    public function test_a_rebuild_first_removes_the_staging_directories_earlier_runs_left(): void
    {
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
        ];
        $git = new TestableGitRepository($this->dind(), $runner);

        $git->cloneConfiguredRepository(static function (): void {});

        $this->assertSame(0, $this->indexOf('sudo find /home/alice -mindepth 1 -maxdepth 1 -name .project-next* -exec rm -rf'));
    }

    public function test_without_a_callback_it_clones_straight_into_the_project(): void
    {
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
        ];
        $git = new TestableGitRepository($this->dind(), $runner);

        $git->cloneConfiguredRepository();

        $this->assertStringEndsWith('/home/alice/project', implode(' ', $runner->commands[0]));
        foreach ($this->events as $event) {
            $this->assertStringNotContainsString('.project-next', $event);
        }
    }

    private function indexOf(string $prefix): int
    {
        foreach ($this->events as $i => $event) {
            if (str_starts_with($event, $prefix)) {
                return $i;
            }
        }
        $this->fail("No command starting with: {$prefix}\n" . implode("\n", $this->events));
    }

    private function dind(): Dind
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
            'git_repo' => 'https://github.com/org/repo.git',
        ]);

        $events = &$this->events;
        $system = new class($events) extends System
        {
            /** @param list<string> $events */
            public function __construct(private array &$events) {}

            public function homesDirPath(): string
            {
                return '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->events[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                return '';
            }
        };

        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
