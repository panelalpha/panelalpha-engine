<?php

namespace Tests\Unit\System;

use App\System\Services\PureFtpd;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\PureFtpdCommandsFixtures;

/** The exact request each PureFtpd call has the root broker answer. */
class PureFtpdCommandsTest extends TestCase
{
    use PureFtpdCommandsFixtures;

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
}
