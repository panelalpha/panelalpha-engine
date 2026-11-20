<?php

namespace Tests\Support;

use App\System;
use App\System\EnginePaths;
use Symfony\Component\Process\Process;

trait PureFtpdCommandsFixtures
{
    private const COMPOSE = EnginePaths::ENGINE_DIR . '/docker-compose.yml';

    /** @return System&object{commands: list<array{0: list<string>, 1: array<string, string>}>, requests: list<list<string>>, processes: list<list<string>>} */
    private function recordingSystem(): System
    {
        return new class () extends System {
            /** @var list<array{0: list<string>, 1: array<string, string>}> */
            public array $commands = [];

            /** @var list<list<string>> */
            public array $requests = [];

            /** @var list<list<string>> */
            public array $processes = [];

            public function __construct()
            {
            }

            public function composeFilePath(): string
            {
                return EnginePaths::ENGINE_DIR . '/docker-compose.yml';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->requests[] = (array) $cmd;
                $this->commands[] = [(array) $cmd, $env];

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->processes[] = (array) $cmd;

                return new Process(['true']);
            }
        };
    }
}
