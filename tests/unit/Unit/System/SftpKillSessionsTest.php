<?php

namespace Tests\Unit\System;

use App\System;
use PHPUnit\Framework\TestCase;

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

    public function test_it_runs_in_the_sftp_container(): void
    {
        $system = $this->fakeSystem("1\n");

        $system->sftp()->killSessions(['alice_sftp']);

        $this->assertSame(
            ['sudo', 'docker', 'compose', '-f', '/engine/docker-compose.yml', 'exec', 'sftp', 'sh', '-c'],
            array_slice($system->command, 0, 9)
        );
    }

    public function test_the_script_matches_a_session_by_login_never_by_uid(): void
    {
        $script = $this->scriptFor(['alice_sftp']);

        $this->assertStringContainsString("'^sshd: alice_sftp(@|\\s|$)'", $script);
        $this->assertStringContainsString('pgrep -f', $script);
        $this->assertStringContainsString('kill -TERM $pids', $script);
        $this->assertStringContainsString('sleep 2', $script);
        $this->assertStringContainsString('kill -KILL $pids', $script);
        $this->assertStringNotContainsString('pkill', $script);
        $this->assertStringNotContainsString(' -u ', $script);
    }

    public function test_the_script_counts_the_monitor_process_one_per_session(): void
    {
        $script = $this->scriptFor(['alice_sftp']);

        $this->assertStringContainsString("'^sshd: alice_sftp \\[priv\\]'", $script);
        $this->assertStringContainsString('pgrep -cf "$pattern"', $script);
        $this->assertStringContainsString('echo "$killed"', $script);
    }

    public function test_every_login_gets_its_own_pattern_and_a_bad_one_is_dropped(): void
    {
        $script = $this->scriptFor(['alice_sftp', 'bob_sftp', 'alice_sftp', 'not a login']);

        $this->assertSame(1, substr_count($script, "'^sshd: alice_sftp \\[priv\\]'"));
        $this->assertSame(1, substr_count($script, "'^sshd: bob_sftp \\[priv\\]'"));
        $this->assertStringNotContainsString('not a login', $script);
    }

    public function test_with_no_login_it_asks_the_container_nothing(): void
    {
        $system = $this->fakeSystem("3\n");

        $this->assertSame(0, $system->sftp()->killSessions([]));
        $this->assertSame([], $system->command);
    }

    private function scriptFor(array $logins): string
    {
        $system = $this->fakeSystem("0\n");
        $system->sftp()->killSessions($logins);

        return (string) end($system->command);
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
