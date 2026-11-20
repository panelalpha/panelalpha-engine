<?php

namespace Tests\Support;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;

trait DindDaemonWaitFixtures
{
    /** What each `docker info` probe reports, and how many were made. */
    private function probe(array $answers): \stdClass
    {
        $state = new \stdClass();
        $state->answers = $answers;
        $state->probes = 0;
        $state->commands = [];

        return $state;
    }

    /**
     * A Dind whose real ShellOperations dispatches into a canned host System:
     * the wrap and the argv are the production ones, only the host is replaced.
     *
     * ShellOperations is final, so nothing about it is subclassed here -- only
     * the Dind it is handed, which is what the wait actually talks to.
     */
    private function dind(\stdClass $state): Dind
    {
        $host = new class ($state) extends System {
            public function __construct(private \stdClass $state)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->state->probes++;
                $this->state->commands[] = is_array($cmd) ? implode(' ', LocalRoot::plain($cmd)) : $cmd;

                $answers = $this->state->answers;
                $up = $answers[$this->state->probes - 1] ?? (end($answers) ?: false);

                $process = Process::fromShellCommandline($up ? 'exit 0' : 'exit 1');
                $process->run();

                return $process;
            }
        };

        $account = new class ($host) extends Dind {
            public function __construct(private System $host)
            {
                // The parent constructor needs the project aggregate, which
                // neither wrap() nor the wait touches with asUser left off.
            }

            public function system(): System
            {
                return $this->host;
            }

            public function composeFilePath(): string
            {
                return '/opt/panelalpha/shared-hosting/users/test/docker-compose.yml';
            }

            public function username(): string
            {
                return 'test';
            }

            public function homeDirPath(): string
            {
                return '/home/test';
            }

            public function userModel(): ModelsUser
            {
                throw new \LogicException('the probe must not run as a user');
            }
        };

        $shell = new ShellOperations($account);

        return new class ($shell, $host) extends Dind {
            public function __construct(private ShellOperations $standIn, private System $host)
            {
            }

            public function shell(): ShellOperations
            {
                return $this->standIn;
            }

            public function system(): System
            {
                return $this->host;
            }

            public function username(): string
            {
                return 'test';
            }
        };
    }
}
