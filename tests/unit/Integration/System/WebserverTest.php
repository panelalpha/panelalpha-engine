<?php

namespace Tests\Integration\System;

use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\WebserverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

class WebserverTest extends TestCase
{
    private static ?string $engineDir = null;

    protected function tearDown(): void
    {
        if (self::$engineDir !== null) {
            rmdir(self::$engineDir);
            self::$engineDir = null;
        }
        parent::tearDown();
    }

    /** The broker checks the engine directory exists before it names a script in it. */
    public static function engineDir(): string
    {
        if (self::$engineDir === null) {
            self::$engineDir = sys_get_temp_dir() . '/pa-webserver-engine-' . bin2hex(random_bytes(4));
            mkdir(self::$engineDir);
        }

        return self::$engineDir;
    }

    /**
     * @param list<string> $request
     * @return list<string>
     */
    private function plannedOnHost(array $request): array
    {
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => self::engineDir()]));
        try {
            return LocalRoot::plain($request);
        } finally {
            putenv('PA_ROOT_LOCAL_ROOTS');
        }
    }

    public function test_nginx_proxy_vhost_path_is_hardcoded_not_current_driver(): void
    {
        $system = new System();
        $driver = new NginxProxy($system);

        $this->assertSame(
            '/opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/vhosts',
            $driver->domainsConfigsDirPath()
        );
        $this->assertFalse($driver->domainConfigExists('no-such-domain.example'));
        $this->assertFalse($system->webserver()->domainExists('no-such-domain.example'));
    }

    public function test_run_change_webserver_script_without_serial(): void
    {
        $system = new class extends System {
            public ?array $lastCommand = null;

            public function engineDirPath(): string
            {
                return WebserverTest::engineDir();
            }

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
        $this->assertSame([LocalRoot::CLIENT, '--host'], array_slice($system->lastCommand, 0, 2), 'on the host');
        $command = $this->plannedOnHost($system->lastCommand);
        $this->assertContains('--set', $command);
        $this->assertContains('litespeed', $command);
        $this->assertContains('--background', $command);
        $this->assertContains(self::engineDir() . '/webserver.sh', $command);
        $this->assertFalse(
            (bool) array_filter(
                $command,
                fn ($arg) => str_starts_with((string) $arg, '--serial-no=')
            )
        );
    }

    public function test_run_change_webserver_script_with_serial(): void
    {
        $system = new class extends System {
            public ?array $lastCommand = null;

            public function engineDirPath(): string
            {
                return WebserverTest::engineDir();
            }

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
        $command = $this->plannedOnHost($system->lastCommand);
        $this->assertContains('--serial-no=SERIAL123', $command);
        $this->assertContains('--background', $command);
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
