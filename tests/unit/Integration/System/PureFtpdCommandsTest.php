<?php

namespace Tests\Integration\System;

use App\System;
use App\System\EnginePaths;
use App\System\Services\PureFtpd;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/** The exact request each PureFtpd call has the root broker answer. */
class PureFtpdCommandsTest extends TestCase
{
    private const COMPOSE = EnginePaths::ENGINE_DIR . '/docker-compose.yml';

    public function test_user_exists_asks_the_broker_for_the_user(): void
    {
        $system = $this->recordingSystem();

        (new PureFtpd($system))->userExists('ftp@example.com');

        $this->assertSame([
            [LocalRoot::CLIENT, 'compose.ftpShowUser', '--', self::COMPOSE, 'ftp@example.com'],
        ], $system->processes);
    }

    public function test_useradd_with_a_quota(): void
    {
        $system = $this->recordingSystem();

        (new PureFtpd($system))->useradd("ftp'user", 'secret', 1001, 1002, '/home/ftpuser/alice/public_html', 512);

        $this->assertSame([
            [LocalRoot::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpAddUser', '--', self::COMPOSE, "ftp'user", '1001', '1002',
                '/home/ftpuser/alice/public_html', '512'],
            [LocalRoot::CLIENT, 'compose.ftpMakeDatabase', '--', self::COMPOSE],
        ], array_column($system->commands, 0));
        $this->assertSame(['--env', 'PA_FTP_PASSWORD'], array_slice($system->requests[0], 1, 2), 'the password reaches the broker in the environment only');
        $this->assertSame([['PA_FTP_PASSWORD' => 'secret'], []], array_column($system->commands, 1));
    }

    public function test_useradd_without_a_quota(): void
    {
        $system = $this->recordingSystem();

        (new PureFtpd($system))->useradd('ftp@example.com', 'secret', 1001, 1001, '/home/ftpuser/alice', 0);

        $this->assertSame(
            [LocalRoot::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpAddUser', '--', self::COMPOSE, 'ftp@example.com', '1001',
                '1001', '/home/ftpuser/alice', ''],
            $system->commands[0][0]
        );
    }

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
