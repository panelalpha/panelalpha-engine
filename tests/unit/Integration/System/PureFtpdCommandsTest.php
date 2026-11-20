<?php

namespace Tests\Integration\System;

use App\System\Services\PureFtpd;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\PureFtpdCommandsFixtures;

/** The exact request each PureFtpd call has the root broker answer. */
class PureFtpdCommandsTest extends TestCase
{
    use PureFtpdCommandsFixtures;

    public function test_user_exists_asks_the_broker_for_the_user(): void
    {
        $system = $this->recordingSystem();

        (new PureFtpd($system))->userExists('ftp@example.com');

        $this->assertSame([
            [LocalRoot::CLIENT, 'compose.ftpShowUser', '--', self::COMPOSE, 'ftp@example.com'],
        ], $system->processes);
    }
}
