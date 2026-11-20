<?php

namespace Tests\Integration\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\PhpHandlerNotRunning;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FpmStackRestartFixtures;

class FpmStackRestartTest extends TestCase
{
    use FpmStackRestartFixtures;

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
        $this->assertSame($this->runnerScript('8.4'), end($system->commands[0]));
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
}
