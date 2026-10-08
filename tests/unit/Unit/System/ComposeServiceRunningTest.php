<?php

namespace Tests\Unit\System;

use App\System;
use PHPUnit\Framework\TestCase;

class ComposeServiceRunningTest extends TestCase
{
    public function test_reads_running_service_names_from_compose_ps(): void
    {
        $system = new class extends System {
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return "sites-http\nftp\n";
            }
        };

        $this->assertTrue($system->isComposeServiceRunning('sites-http'));
        $this->assertTrue($system->isComposeServiceRunning('ftp'));
        $this->assertFalse($system->isComposeServiceRunning('mail'));
    }
}
