<?php

namespace Tests\Integration\System\Services\Webserver;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Domain;
use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver;
use App\System\Services\Webserver\AbstractWebserver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * What a webserver driver gets without overriding anything: refusals for what it cannot do, the
 * app-lite fallback proxy, the ACME challenge switch, and its per-webserver directories.
 */
class AbstractWebserverDefaultsTest extends TestCase
{
    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        parent::tearDown();
    }

    public function test_details_name_an_unknown_webserver(): void
    {
        $this->assertSame(
            ['name' => 'Unknown', 'version' => 'Unknown', 'slug' => 'unknown', 'metadata' => []],
            $this->driver($this->host())->getDetails()
        );
    }

    public function test_a_web_panel_password_and_config_entries_are_not_supported(): void
    {
        $driver = $this->driver($this->host());

        $this->assertThrows(fn () => $driver->resetWebPanelPassword(), \Exception::class, 'Operation not supported on current webserver');
        $this->assertThrows(fn () => $driver->updateConfig('serial_number', 'X'), \Exception::class, 'Unsupported config entry for current webserver');
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function unimplemented(): iterable
    {
        yield 'rebuildConfig' => ['rebuildConfig', []];
        yield 'reload' => ['reload', []];
        yield 'reloadRouting' => ['reloadRouting', []];
        yield 'restart' => ['restart', []];
        yield 'listDomains' => ['listDomains', []];
        yield 'deleteDomainConfig' => ['deleteDomainConfig', ['alice.test']];
        yield 'deleteDomainsConfigs' => ['deleteDomainsConfigs', [['alice.test']]];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('unimplemented')]
    public function test_an_operation_the_driver_does_not_implement_says_so(string $method, array $arguments): void
    {
        $this->expectExceptionMessageMatches('/^Method .*AbstractWebserver::\w+ not implemented for class /');

        $this->driver($this->host())->{$method}(...$arguments);
    }

    public function test_a_domain_operation_the_driver_does_not_implement_says_so(): void
    {
        $driver = $this->driver($this->host());
        $domain = new Domain(['domain' => 'alice.test']);

        foreach (['rebuildDomainConfig', 'addDomain', 'createDomainConfig'] as $method) {
            $this->assertThrows(fn () => $driver->{$method}($domain), \Exception::class, "AbstractWebserver::{$method} not implemented");
        }
    }

    public function test_an_address_listener_needs_no_rebind_by_default(): void
    {
        $this->assertFalse($this->driver($this->host())->needsIpListenerRebind());
    }

    public function test_the_directories_follow_the_current_webserver(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'apache');
        $driver = $this->driver($this->host());

        $this->assertSame('/opt/engine/webserver-logs/apache', $driver->logsDirPath());
        $this->assertSame('/opt/engine/webserver-config/apache/vhosts', $driver->domainsConfigsDirPath());
    }

    public function test_deleting_logs_removes_each_domain_tree_under_the_driver_slug(): void
    {
        $host = $this->host();

        $this->driver($host)->deleteDomainsLogsDirs(['alice.test', 'bob.test']);

        $this->assertSame(
            [['files.removeTree', ['/opt/engine/webserver-logs/unknown/alice.test']], ['files.removeTree', ['/opt/engine/webserver-logs/unknown/bob.test']]],
            array_map(static fn (array $argv): array => [LocalRoot::action($argv), LocalRoot::values($argv)], $host->commands)
        );
    }

    public function test_the_fallback_proxy_is_off_without_the_app_lite_proxy(): void
    {
        config(['env.APP_LITE_PROXY_ENABLED' => false, 'env.APP_LITE_PROXY_CA_HOST' => 'panel']);

        $this->assertSame(self::noFallback(), $this->driver($this->host())->fallback());
    }

    public function test_the_fallback_proxy_is_off_when_the_client_area_has_its_own_port_or_domain(): void
    {
        config(['env.APP_LITE_PROXY_ENABLED' => true, 'env.APP_LITE_CA_PORT' => 8443, 'env.APP_LITE_CA_DOMAIN' => null]);
        $this->assertSame(self::noFallback(), $this->driver($this->host())->fallback());

        config(['env.APP_LITE_CA_PORT' => null, 'env.APP_LITE_CA_DOMAIN' => 'panel.example.test']);
        $this->assertSame(self::noFallback(), $this->driver($this->host())->fallback());
    }

    public function test_the_fallback_proxy_points_at_the_client_area_otherwise(): void
    {
        config([
            'env.APP_LITE_PROXY_ENABLED' => true, 'env.APP_LITE_CA_PORT' => null, 'env.APP_LITE_CA_DOMAIN' => null,
            'env.APP_LITE_PROXY_CA_HOST' => 'panel-client', 'env.APP_LITE_PROXY_CA_PORT' => 9090,
        ]);

        $this->assertSame(
            ['fallback_proxy' => true, 'fallback_proxy_host' => 'panel-client', 'fallback_proxy_port' => 9090],
            $this->driver($this->host())->fallback()
        );
    }

    public function test_acme_challenges_are_off_without_their_directory(): void
    {
        $host = $this->host(directoryExists: false);

        $vars = $this->driver($host)->acme(new Domain(['domain' => 'alice.test']));

        $this->assertSame(['http_acme_challenges_enabled' => false, 'http_acme_challenges_dir' => '/opt/engine/data/webserver/acme-challenges/alice.test'], $vars);
        $this->assertSame([], $host->commands);
    }

    public function test_acme_challenges_are_off_when_the_directory_cannot_be_listed(): void
    {
        $host = $this->host(listing: FakeProcess::failed('permission denied'));

        $this->assertFalse($this->driver($host)->acme(new Domain(['domain' => 'alice.test']))['http_acme_challenges_enabled']);
        $this->assertSame(['files.listAllNames'], array_map(static fn (array $argv): ?string => LocalRoot::action($argv), $host->commands));
    }

    public function test_acme_challenges_are_off_for_an_empty_directory(): void
    {
        $host = $this->host(listing: FakeProcess::ok(".\n..\n"));

        $this->assertFalse($this->driver($host)->acme(new Domain(['domain' => 'alice.test']))['http_acme_challenges_enabled']);
    }

    public function test_acme_challenges_are_on_while_a_token_waits(): void
    {
        $host = $this->host(listing: FakeProcess::ok(".\n..\nZr4Kx9token\n"));

        $this->assertTrue($this->driver($host)->acme(new Domain(['domain' => 'alice.test']))['http_acme_challenges_enabled']);
    }

    /** @return array{fallback_proxy: bool, fallback_proxy_host: string, fallback_proxy_port: int} */
    private static function noFallback(): array
    {
        return ['fallback_proxy' => false, 'fallback_proxy_host' => 'localhost', 'fallback_proxy_port' => 8080];
    }

    private function driver(System $host): AbstractWebserver
    {
        return new class ($host) extends AbstractWebserver {
            /** @return array<string, mixed> */
            public function fallback(): array
            {
                return $this->getFallbackVars();
            }

            /** @return array<string, mixed> */
            public function acme(Domain $domain): array
            {
                return $this->httpAcmeChallengeTemplateVars($domain);
            }
        };
    }

    private function host(bool $directoryExists = true, ?Process $listing = null): System
    {
        return new class ($directoryExists, $listing ?? FakeProcess::ok()) extends System {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private bool $directoryExists, private Process $listing) {}

            public function engineDirPath(): string
            {
                return '/opt/engine';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = array_values((array) $cmd);

                return $this->listing;
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->directoryExists) extends Filesystem {
                    public function __construct(System $system, private bool $exists)
                    {
                        parent::__construct($system);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return $this->exists;
                    }
                };
            }
        };
    }
}
