<?php

namespace Tests\Unit\System\Services;

use App\Models\Domain;
use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\Apache;
use App\System\Services\Webserver\Litespeed;
use App\System\Services\Webserver\Nginx;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\Openlitespeed;
use App\System\Services\Webserver\WebserverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The engine's webserver hands every operation to the driver of the current webserver, unchanged. */
class WebserverDelegationTest extends TestCase
{
    /** @return iterable<string, array{string, class-string}> */
    public static function slugs(): iterable
    {
        yield 'litespeed' => ['litespeed', Litespeed::class];
        yield 'openlitespeed' => ['openlitespeed', Openlitespeed::class];
        yield 'apache' => ['apache', Apache::class];
        yield 'nginx' => ['nginx', Nginx::class];
        yield 'nginx-proxy' => ['nginx-proxy', NginxProxy::class];
    }

    /** @param class-string $class */
    #[DataProvider('slugs')]
    public function test_each_slug_picks_its_driver(string $slug, string $class): void
    {
        $this->assertInstanceOf($class, (new Webserver(new System()))->driver($slug));
    }

    public function test_an_unknown_slug_is_refused(): void
    {
        $this->expectExceptionMessage('Invalid webserver: caddy');

        (new Webserver(new System()))->driver('caddy');
    }

    /** @return iterable<string, array{string, list<mixed>, mixed}> */
    public static function operations(): iterable
    {
        yield 'resetWebPanelPassword' => ['resetWebPanelPassword', [], 'new-password'];
        yield 'updateConfig' => ['updateConfig', ['serial_number', 'ABC'], null];
        yield 'rebuildConfig' => ['rebuildConfig', [null], null];
        yield 'reload' => ['reload', [true], null];
        yield 'reloadRouting' => ['reloadRouting', [], null];
        yield 'needsIpListenerRebind' => ['needsIpListenerRebind', [], true];
        yield 'restart' => ['restart', [], null];
        yield 'listDomains' => ['listDomains', [], ['alice.test', 'bob.test']];
        yield 'deleteDomainConfig' => ['deleteDomainConfig', ['alice.test'], null];
        yield 'deleteDomainsConfigs' => ['deleteDomainsConfigs', [['alice.test', 'bob.test']], null];
        yield 'deleteDomainLogsDir' => ['deleteDomainLogsDir', ['alice.test'], null];
        yield 'deleteDomainsLogsDirs' => ['deleteDomainsLogsDirs', [['alice.test']], null];
        yield 'toggleModsecurity' => ['toggleModsecurity', [], null];
        yield 'toggleLscache' => ['toggleLscache', [], null];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('operations')]
    public function test_an_operation_reaches_the_driver_with_its_arguments(string $method, array $arguments, mixed $answer): void
    {
        $driver = $this->createMock(WebserverInterface::class);
        $expectation = $driver->expects($this->once())->method($method)->with(...$arguments);
        if ($answer !== null) {
            $expectation->willReturn($answer);
        }

        $result = $this->webserver($driver)->{$method}(...$arguments);

        $this->assertSame($answer, $result);
    }

    public function test_a_domain_operation_hands_the_same_domain_on(): void
    {
        $domain = $this->createStub(Domain::class);
        $driver = $this->createMock(WebserverInterface::class);
        $driver->expects($this->once())->method('rebuildDomainConfig')->with($this->identicalTo($domain));
        $driver->expects($this->once())->method('addDomain')->with($this->identicalTo($domain));

        $webserver = $this->webserver($driver);
        $webserver->rebuildDomainConfig($domain);
        $webserver->addDomain($domain);
    }

    public function test_only_nginx_proxy_has_proxy_rule_certificates_to_refresh(): void
    {
        $this->assertFalse($this->webserver($this->createStub(WebserverInterface::class))->refreshProxyRuleCertificates());
    }

    public function test_unchanged_proxy_rule_certificates_do_not_reload(): void
    {
        $driver = $this->createMock(NginxProxy::class);
        $driver->method('refreshProxyRuleCertificates')->willReturn(false);
        $driver->expects($this->never())->method('reloadRouting');

        $this->assertFalse($this->webserver($driver)->refreshProxyRuleCertificates());
    }

    public function test_changed_proxy_rule_certificates_reload_the_routing(): void
    {
        $driver = $this->createMock(NginxProxy::class);
        $driver->method('refreshProxyRuleCertificates')->willReturn(true);
        $driver->expects($this->once())->method('reloadRouting');

        $this->assertTrue($this->webserver($driver)->refreshProxyRuleCertificates());
    }

    private function webserver(WebserverInterface $driver): Webserver
    {
        return new class (new System(), $driver) extends Webserver {
            public function __construct(System $system, private WebserverInterface $fixed)
            {
                parent::__construct($system);
            }

            public function driver(?string $slug = null): WebserverInterface
            {
                return $this->fixed;
            }
        };
    }
}
