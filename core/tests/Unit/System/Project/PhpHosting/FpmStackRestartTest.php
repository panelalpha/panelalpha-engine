<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\PhpHandlerNotRunning;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FpmStackRestartTest extends TestCase
{
    public function test_script_targets_only_this_versions_master(): void
    {
        $pattern = $this->masterPattern(FpmStack::restartFpmScript('8.4'));

        $this->assertMatchesRegularExpression($pattern, 'php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: master process (/etc/php/8x4/fpm/php-fpm.conf)');
        $this->assertDoesNotMatchRegularExpression($pattern, 'php-fpm: pool www');
        // The anchor is what keeps pkill off the `bash -c` running the script.
        $this->assertDoesNotMatchRegularExpression($pattern, 'bash -c ' . FpmStack::restartFpmScript('8.4'));
    }

    public function test_script_stops_every_master_before_the_runner_starts_one(): void
    {
        $script = FpmStack::restartFpmScript('8.4');

        $stop = strpos($script, 'entrypoint-runner.sh stop php-fpm8.4');
        $kill = strpos($script, 'pkill -QUIT');
        $start = strpos($script, 'entrypoint-runner.sh start php-fpm8.4');
        $this->assertNotFalse($stop);
        $this->assertNotFalse($kill);
        $this->assertNotFalse($start);
        $this->assertLessThan($kill, $stop);
        $this->assertLessThan($start, $kill);
    }

    public function test_a_version_the_runner_does_not_manage_is_left_alone(): void
    {
        $script = FpmStack::restartFpmScript('8.3');

        // First line, so nothing is stopped for a version no domain uses yet,
        // and a status of its own rather than a success.
        $this->assertStringStartsWith(
            '[ -f /entrypoint.d/php-fpm8.3.sh ] || exit ' . FpmStack::EXIT_NOT_MANAGED,
            $script
        );
    }

    public function test_title_matched_stop_runs_only_for_a_master_the_runner_left_behind(): void
    {
        $script = FpmStack::restartFpmScript('8.4');

        $this->assertStringContainsString('if [ -n "$stray" ]; then', $script);
        $this->assertLessThan(
            strpos($script, 'pkill -QUIT'),
            strpos($script, 'if [ -n "$stray" ]; then')
        );
        $this->assertStringContainsString('the runner did not start: $stray', $script);
    }

    public function test_rejects_a_version_that_is_not_major_dot_minor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FpmStack::restartFpmScript("8.4'; rm -rf /; '");
    }

    public function test_an_unmanaged_version_is_reported_as_such_not_as_a_restart(): void
    {
        [$system, $model, $project] = $this->accountRunning('exit ' . FpmStack::EXIT_NOT_MANAGED);

        $this->expectException(PhpHandlerNotRunning::class);
        (new FpmStack($system, $model))->restartPhpHandler($project, '8.3');
    }

    public function test_a_failed_restart_is_reported_not_swallowed(): void
    {
        $system = new class extends System {
            /** @var list<string|array<string>> */
            public array $commands = [];

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                $process = new Process(['sh', '-c', 'echo "php-fpm8.4 did not start" >&2; exit 1']);
                $process->run();

                return $process;
            }
        };
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default']);
        $project = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $project);

        try {
            (new FpmStack($system, $model))->restartPhpHandler($project, '8.4');
            $this->fail('A restart that never brought FPM back must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('php-fpm8.4 did not start', $e->getMessage());
        }

        $this->assertCount(1, $system->commands);
        $this->assertIsArray($system->commands[0]);
        $this->assertSame(FpmStack::restartFpmScript('8.4'), end($system->commands[0]));
    }

    /**
     * @return array{0: System, 1: ModelsUser, 2: PhpHosting}
     */
    private function accountRunning(string $shell): array
    {
        $system = new class extends System {
            public string $shell = '';

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['sh', '-c', $this->shell]);
                $process->run();

                return $process;
            }
        };
        $system->shell = $shell;
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default']);
        $project = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $project);

        return [$system, $model, $project];
    }

    private function masterPattern(string $script): string
    {
        $this->assertSame(1, preg_match("/pkill -QUIT -f '([^']+)'/", $script, $m));

        return '#' . $m[1] . '#';
    }
}
