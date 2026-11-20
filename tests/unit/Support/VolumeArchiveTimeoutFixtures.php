<?php

namespace Tests\Support;

use App\System;
use App\System\Project\Dind;
use Symfony\Component\Process\Process;

trait VolumeArchiveTimeoutFixtures
{
    /** @param list<array{0: string, 1: list<string>}> $asked */
    private function recordingSystem(array &$timeouts, array &$asked = []): System
    {
        return new class ($timeouts, $asked) extends System {
            public function __construct(private array &$timeouts, private array &$asked) {}

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;
                if (LocalRoot::isRequest($argv) && ($argv[1] ?? '') === '--host') {
                    return $this->execOnHost($argv, $env, $timeout);
                }
                $this->timeouts[] = $timeout;
                if (LocalRoot::isRequest($argv)) {
                    $this->asked[] = [(string) LocalRoot::action($argv), LocalRoot::values($argv)];
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['php', '-r', '']);
            }
        };
    }

    private function standInDind(System $system): Dind
    {
        return new class ($system) extends Dind {
            public function __construct(private System $host) {}

            public function system(): System
            {
                return $this->host;
            }

            public function username(): string
            {
                return 'test';
            }

            public function homeDirPath(): string
            {
                return '/home/test';
            }

            public function composeFilePath(): string
            {
                return '/home/test/docker-compose.yml';
            }
        };
    }
}
