<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\WebserverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class WebserverTest extends TestCase
{
    public function test_nginx_proxy_vhost_path_is_hardcoded_not_current_driver(): void
    {
        $system = new System();
        $driver = new NginxProxy($system);

        $this->assertSame(
            '/opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/vhosts',
            $driver->domainsConfigDirPath()
        );
        $this->assertFalse($driver->domainConfigExists('no-such-domain.example'));
        $this->assertFalse($system->webserver()->domainExists('no-such-domain.example'));
    }

    public function test_run_change_webserver_script_without_serial(): void
    {
        $system = new class extends System {
            public ?array $lastCommand = null;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->lastCommand = is_array($cmd) ? $cmd : [$cmd];
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };

        $system->webserver()->runChangeWebserverScript('litespeed');

        $this->assertNotNull($system->lastCommand);
        $this->assertContains('--set', $system->lastCommand);
        $this->assertContains('litespeed', $system->lastCommand);
        $this->assertContains('--background', $system->lastCommand);
        $this->assertContains(
            '/opt/panelalpha/shared-hosting/webserver.sh',
            $system->lastCommand
        );
        $this->assertFalse(
            (bool) array_filter(
                $system->lastCommand,
                fn ($arg) => str_starts_with((string) $arg, '--serial-no=')
            )
        );
    }

    public function test_run_change_webserver_script_with_serial(): void
    {
        $system = new class extends System {
            public ?array $lastCommand = null;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->lastCommand = is_array($cmd) ? $cmd : [$cmd];
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };

        $system->webserver()->runChangeWebserverScript('litespeed', 'SERIAL123');

        $this->assertNotNull($system->lastCommand);
        $this->assertContains('--serial-no=SERIAL123', $system->lastCommand);
        $this->assertContains('--background', $system->lastCommand);
    }

    public function test_is_running_asks_compose_for_sites_http(): void
    {
        $system = new class extends System {
            public function isComposeServiceRunning(string $service): bool
            {
                return $service === 'sites-http';
            }
        };

        $this->assertTrue($system->webserver()->isRunning());
    }

    public function test_rebuild_domains_rebuilds_config_then_reloads_once(): void
    {
        $calls = [];
        $driver = $this->createMock(WebserverInterface::class);
        $driver->expects($this->once())
            ->method('rebuildConfig')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'rebuildConfig';
            });
        $driver->expects($this->once())
            ->method('reload')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'reload';
            });

        $webserver = new class(new System(), $driver) extends Webserver {
            public function __construct(
                System $system,
                private WebserverInterface $stubDriver,
            ) {
                parent::__construct($system);
            }

            public function driver(?string $slug = null): WebserverInterface
            {
                return $this->stubDriver;
            }
        };

        $webserver->rebuildDomains();

        $this->assertSame(['rebuildConfig', 'reload'], $calls);
    }

    public function test_facade_get_details_delegates_to_driver(): void
    {
        $expected = ['slug' => 'nginx', 'name' => 'Nginx'];
        $driver = $this->createMock(WebserverInterface::class);
        $driver->expects($this->once())
            ->method('getDetails')
            ->willReturn($expected);

        $webserver = new class(new System(), $driver) extends Webserver {
            public function __construct(
                System $system,
                private WebserverInterface $stubDriver,
            ) {
                parent::__construct($system);
            }

            public function driver(?string $slug = null): WebserverInterface
            {
                return $this->stubDriver;
            }
        };

        $this->assertInstanceOf(WebserverInterface::class, $webserver);
        $this->assertSame($expected, $webserver->getDetails());
    }
}
