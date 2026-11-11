<?php

namespace Tests\Unit\System;

use App\System;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class SftpKillSessionsTest extends TestCase
{
    public function test_it_answers_the_session_count_the_container_printed(): void
    {
        $this->assertSame(2, $this->fakeSystem("2\n")->sftp()->killSessions(['alice_sftp']));
    }

    public function test_no_session_answers_zero(): void
    {
        $this->assertSame(0, $this->fakeSystem("0\n")->sftp()->killSessions(['alice_sftp']));
    }

    public function test_it_asks_the_broker_for_the_sftp_service_with_the_login(): void
    {
        $system = $this->fakeSystem("1\n");

        $system->sftp()->killSessions(['alice_sftp']);

        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.sftpKillSessions', '--', '/engine/docker-compose.yml', 'alice_sftp'],
            $system->command
        );
    }

    public function test_every_login_travels_once_and_only_as_a_login(): void
    {
        $system = $this->fakeSystem("0\n");

        $system->sftp()->killSessions(['alice_sftp', 'bob_sftp', 'alice_sftp', 'not a login', '']);

        $this->assertSame(
            [LocalRoot::CLIENT, 'compose.sftpKillSessions', '--', '/engine/docker-compose.yml', 'alice_sftp', 'bob_sftp'],
            $system->command
        );
    }

    public function test_with_no_login_it_asks_the_container_nothing(): void
    {
        $system = $this->fakeSystem("3\n");

        $this->assertSame(0, $system->sftp()->killSessions([]));
        $this->assertSame([], $system->command);
    }

    private function fakeSystem(string $output): System
    {
        return new class ($output) extends System {
            /** @var list<string> */
            public array $command = [];

            public function __construct(
                private string $output,
            ) {
            }

            public function composeFilePath(): string
            {
                return '/engine/docker-compose.yml';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->command = is_array($cmd) ? array_values($cmd) : [$cmd];

                return $this->output;
            }
        };
    }
}
