<?php

namespace Tests\Integration\System;

use App\System;
use PHPUnit\Framework\TestCase;

class WebserverIsActiveTest extends TestCase
{
    private string $engineDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engineDir = sys_get_temp_dir() . '/pa-ws-active-' . bin2hex(random_bytes(4));
        mkdir($this->engineDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->engineDir . '/docker-compose.yml-webserver');
        rmdir($this->engineDir);
        parent::tearDown();
    }

    public function test_the_running_webserver_is_active(): void
    {
        $this->selectWebserver('nginx-proxy');

        $this->assertTrue($this->system(sitesHttpRunning: true)->isWebserverActive('nginx-proxy'));
    }

    public function test_another_webserver_is_not_active(): void
    {
        $this->selectWebserver('nginx');

        $this->assertFalse($this->system(sitesHttpRunning: true)->isWebserverActive('nginx-proxy'));
    }

    /** A switch to the selected webserver is what brings a stopped sites-http back. */
    public function test_a_stopped_sites_http_is_not_active(): void
    {
        $this->selectWebserver('nginx-proxy');

        $this->assertFalse($this->system(sitesHttpRunning: false)->isWebserverActive('nginx-proxy'));
    }

    public function test_a_webserver_that_cannot_be_read_is_not_active(): void
    {
        $this->assertFalse($this->system(sitesHttpRunning: true)->isWebserverActive('nginx-proxy'));
    }

    private function selectWebserver(string $webserver): void
    {
        file_put_contents(
            $this->engineDir . '/docker-compose.yml-webserver',
            "services:\n  sites-http:\n    labels:\n      - com.panelalpha.webserver={$webserver}\n"
        );
    }

    private function system(bool $sitesHttpRunning): System
    {
        return new class ($this->engineDir, $sitesHttpRunning) extends System {
            public function __construct(private string $engineDir, private bool $sitesHttpRunning)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineDir;
            }

            public function isComposeServiceRunning(string $service): bool
            {
                return $service === 'sites-http' && $this->sitesHttpRunning;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('docker is not reachable');
            }
        };
    }
}
