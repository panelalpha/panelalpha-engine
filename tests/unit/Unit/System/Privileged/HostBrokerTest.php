<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootBuildCache;
use App\System\Privileged\RootHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostBrokerCases;
use Tests\Support\TestNetwork;

final class HostBrokerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const ENGINE = '/opt/panelalpha/shared-hosting';

    public function test_the_cases_the_broker_is_checked_against_are_the_gateways_own(): void
    {
        $this->assertSame(array_map(static fn (array $case): array => array_diff_key($case, ['sudo' => true]), $this->recorded()), HostBrokerCases::all());
    }

    public function test_every_case_asks_the_broker_and_none_runs_sudo(): void
    {
        foreach (HostBrokerCases::all() as $case) {
            $this->assertSame(self::CLIENT, $case['broker'][0], $case['name']);
            $this->assertNotContains('sudo', $case['broker'], $case['name']);
            $this->assertNotContains('nsenter', $case['broker'], $case['name']);
        }
    }

    public function test_a_command_sudo_ran_in_the_hosts_namespaces_asks_the_broker_to_enter_them(): void
    {
        foreach ($this->recorded() as $case) {
            if (! isset($case['sudo'])) {
                $action = $case['broker'][1] === '--host' ? $case['broker'][2] : $case['broker'][1];
                $this->assertSame(str_starts_with($action, 'host.'), $case['broker'][1] === '--host', $case['name']);

                continue;
            }
            $this->assertSame($case['sudo'][1] === 'nsenter', $case['broker'][1] === '--host', $case['name']);
        }
    }

    public function test_a_host_request_is_not_wrapped_in_nsenter_again(): void
    {
        $argv = Privileged::host()->processExists(4321);

        $this->assertSame([self::CLIENT, '--host', 'host.processExists', '--', '4321'], $argv);
        $this->assertSame($argv, Privileged::onHost($argv));
    }

    public function test_lists_travel_as_counted_values(): void
    {
        $this->assertSame(
            [self::CLIENT, '--host', 'host.classicAppTypes', '--', '2', 'a', '/home/a', '/home/a/x', '', 'b', '/home/b', '/home/b/y', '/home/b/y/blog'],
            Privileged::host()->classicAppTypes([
                'a' => ['home' => '/home/a', 'docroot' => '/home/a/x', 'install' => ''],
                'b' => ['home' => '/home/b', 'docroot' => '/home/b/y', 'install' => '/home/b/y/blog'],
            ])
        );
        $this->assertSame(
            [self::CLIENT, '--host', 'host.removeAccountDockerStore', '--', 'pa-nm-a', '1', 'redis:7'],
            Privileged::host()->removeAccountDockerStore('pa-nm-a', ['redis:7'])
        );
        $this->assertSame(
            [self::CLIENT, '--host', 'host.removeAccountDockerFiles', '--', '/c/a', '0', '/home/a/docker'],
            Privileged::host()->removeAccountDockerFiles('/c/a', [], '/home/a/docker')
        );
    }

    public function test_the_license_key_and_serial_are_values_the_broker_keeps_out_of_its_audit(): void
    {
        $this->assertSame(
            [self::CLIENT, '--host', 'host.enqueueEngineUpdate', '--', self::ENGINE, 'LIC-1'],
            Privileged::host()->enqueueEngineUpdate(self::ENGINE, 'LIC-1')
        );
        $this->assertSame(
            [self::CLIENT, '--host', 'host.changeWebserver', '--', self::ENGINE, 'litespeed', ''],
            Privileged::host()->changeWebserver(self::ENGINE, 'litespeed', null)
        );
    }

    /** @return iterable<string, array{\Closure(RootHost): mixed}> */
    public static function refusedHost(): iterable
    {
        yield 'docker volume that is an option' => [fn (RootHost $host) => $host->removeAccountDockerStore('-v', [])];
        yield 'image with a command' => [fn (RootHost $host) => $host->removeAccountDockerStore('pa-nm-a', ['redis:7; id'])];
        yield 'project cache climbing out' => [fn (RootHost $host) => $host->removeAccountDockerFiles('/c/a/../..', [], '/home/a/docker')];
        yield 'relative legacy cache' => [fn (RootHost $host) => $host->removeAccountDockerFiles('/c/a', ['c/b'], '/home/a/docker')];
        yield 'build network that is an option' => [fn (RootHost $host) => $host->applyBuildNetworkFirewall('--help')];
        yield 'build network with a space' => [fn (RootHost $host) => $host->applyBuildNetworkFirewall('a b')];
        yield 'cache username climbing out' => [fn (RootHost $host) => $host->prepareProjectCache('../root', '0:0')];
        yield 'cache owner that is not uid:gid' => [fn (RootHost $host) => $host->prepareProjectCache('alice', '1004:1004; rm -rf /')];
        yield 'negative cache age' => [fn (RootHost $host) => $host->pruneProjectCaches(-1, false, [])];
        yield 'skipped username with a comma' => [fn (RootHost $host) => $host->pruneProjectCaches(60, false, ['a,b'])];
        yield 'skipped username that is an option' => [fn (RootHost $host) => $host->pruneProjectCaches(60, false, ['--dry-run'])];
        yield 'HTTP method that is an option' => [fn (RootHost $host) => $host->httpRequest('-o/etc/x', 'http://'.TestNetwork::LOOPBACK.':5000/v2/', [])];
        yield 'URL that is an option' => [fn (RootHost $host) => $host->httpRequest('GET', '--output=/etc/passwd', [])];
        yield 'URL with a space' => [fn (RootHost $host) => $host->httpRequest('GET', 'http://'.TestNetwork::LOOPBACK.':5000/a b', [])];
        yield 'file URL' => [fn (RootHost $host) => $host->httpRequest('GET', 'file:///etc/shadow', [])];
        yield 'HTTP method the broker does not send' => [fn (RootHost $host) => $host->httpRequest('POST', 'http://'.TestNetwork::LOOPBACK.':5000/v2/', [])];
        yield 'header the broker does not send' => [fn (RootHost $host) => $host->httpRequest('GET', 'http://'.TestNetwork::LOOPBACK.':5000/v2/', ['Content-Type' => 'application/json'])];
        yield 'URL of another host' => [fn (RootHost $host) => $host->httpRequest('GET', 'http://example.com/v2/', [])];
        yield 'URL beside the registry API' => [fn (RootHost $host) => $host->httpRequest('GET', 'http://'.TestNetwork::LOOPBACK.':5000/debug/vars', [])];
        yield 'header with a line break' => [fn (RootHost $host) => $host->httpRequest('GET', 'http://'.TestNetwork::LOOPBACK.':5000/v2/', ['Accept' => "a\r\nX: y"])];
        yield 'header name with a space' => [fn (RootHost $host) => $host->httpRequest('GET', 'http://'.TestNetwork::LOOPBACK.':5000/v2/', ['A b' => 'c'])];
        yield 'engine dir climbing out' => [fn (RootHost $host) => $host->requestEngineCertificate('/opt/../tmp', [], [])];
        yield 'certificate domain with a space' => [fn (RootHost $host) => $host->requestEngineCertificate('/opt/e', ['domain' => 'a.com --staging'], [])];
        yield 'certificate domain that is an option' => [fn (RootHost $host) => $host->requestEngineCertificate('/opt/e', ['domain' => '-a.com'], [])];
        yield 'certificate email with a space' => [fn (RootHost $host) => $host->requestEngineCertificate('/opt/e', ['email' => 'a@b.com x'], [])];
        yield 'certificate email that is an option' => [fn (RootHost $host) => $host->requestEngineCertificate('/opt/e', ['email' => '-a@b.com'], [])];
        yield 'certificate ip that is not one' => [fn (RootHost $host) => $host->requestEngineCertificate('/opt/e', ['ip' => TestNetwork::public(4).';id'], [])];
        yield 'recovery username with a space' => [fn (RootHost $host) => $host->recoverDindAccount('alice --all-stuck', false, false)];
        yield 'recovery username that is an option' => [fn (RootHost $host) => $host->recoverDindAccount('-x', false, false)];
        yield 'license key that is an option' => [fn (RootHost $host) => $host->enqueueEngineUpdate('/opt/e', '--no-verify')];
        yield 'license key with a line break' => [fn (RootHost $host) => $host->enqueueEngineUpdate('/opt/e', "a\nb")];
        yield 'relative engine dir for the update' => [fn (RootHost $host) => $host->enqueueEngineUpdate('opt/e', null)];
        yield 'webserver with a command' => [fn (RootHost $host) => $host->changeWebserver('/opt/e', 'nginx;id', null)];
        yield 'webserver that is an option' => [fn (RootHost $host) => $host->changeWebserver('/opt/e', '--help', null)];
        yield 'serial number with a line break' => [fn (RootHost $host) => $host->changeWebserver('/opt/e', 'litespeed', "a\nb")];
        yield 'own image that is an option' => [fn (RootHost $host) => $host->ensureOwnImage('--all-tags')];
        yield 'own image with a command' => [fn (RootHost $host) => $host->ensureOwnImage('panelalpha/php:8.3;id')];
        yield 'public image that is an option' => [fn (RootHost $host) => $host->refreshPublicImage('-a')];
        yield 'stats username with a space' => [fn (RootHost $host) => $host->projectStats(['shop blog'], 45)];
        yield 'stats timeout of zero' => [fn (RootHost $host) => $host->projectStats(['shop'], 0)];
        yield 'disk home climbing out' => [fn (RootHost $host) => $host->projectDisk(['shop' => '/home/shop/../root'])];
        yield 'disk username that is an option' => [fn (RootHost $host) => $host->projectDisk(['-x' => '/home/x'])];
        yield 'app type docroot climbing out' => [fn (RootHost $host) => $host->classicAppTypes(['shop' => ['home' => '/home/shop', 'docroot' => '/home/shop/../../etc', 'install' => '']])];
        yield 'app type install path climbing out' => [fn (RootHost $host) => $host->classicAppTypes(['shop' => ['home' => '/home/shop', 'docroot' => '/home/shop/x', 'install' => '/home/shop/../../etc']])];
        yield 'app type relative home' => [fn (RootHost $host) => $host->classicAppTypes(['shop' => ['home' => 'home/shop', 'docroot' => '/home/shop/x', 'install' => '']])];
        yield 'edge domain with a space' => [fn (RootHost $host) => $host->edgeProbe('a.com b', TestNetwork::public(7), 4, false)];
        yield 'edge ip that is not one' => [fn (RootHost $host) => $host->edgeProbe('a.com', TestNetwork::public(7).';id', 4, false)];
        yield 'pid zero' => [fn (RootHost $host) => $host->processExists(0)];
        yield 'negative pid' => [fn (RootHost $host) => $host->processExists(-1)];
    }

    /** @param  \Closure(RootHost): mixed  $build */
    #[DataProvider('refusedHost')]
    public function test_a_host_value_the_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(Privileged::host());
    }

    /** @return iterable<string, array{\Closure(RootBuildCache): mixed}> */
    public static function refusedBuildCache(): iterable
    {
        yield 'network that is an option' => [fn (RootBuildCache $cache) => $cache->buildKit('--privileged', '4g', 1, [], [], [])];
        yield 'network with a space' => [fn (RootBuildCache $cache) => $cache->buildKit('a b', '4g', 1, [], [], [])];
        yield 'memory with an option' => [fn (RootBuildCache $cache) => $cache->buildKit(null, '4g --privileged', 1, [], [], [])];
        yield 'no pids' => [fn (RootBuildCache $cache) => $cache->buildKit(null, '4g', 0, [], [], [])];
        yield 'mount source with a colon' => [fn (RootBuildCache $cache) => $cache->buildKit(null, '4g', 1, ['/src' => '/:/host'], [], [])];
        yield 'relative mount source' => [fn (RootBuildCache $cache) => $cache->buildKit(null, '4g', 1, [], ['/out' => 'out'], [])];
        yield 'mount source climbing out' => [fn (RootBuildCache $cache) => $cache->buildKit(null, '4g', 1, ['/src' => '/w/../../'], [], [])];
        yield 'relative mount target' => [fn (RootBuildCache $cache) => $cache->buildKit(null, '4g', 1, ['src' => '/w/src'], [], [])];
        yield 'account image that is an option' => [fn (RootBuildCache $cache) => $cache->railpackPrepare(null, '4g', 1, '--privileged', '/w/src', '/w/plan')];
        yield 'account image with a space' => [fn (RootBuildCache $cache) => $cache->railpackPrepare(null, '4g', 1, 'a b', '/w/src', '/w/plan')];
        yield 'plan dir with a colon' => [fn (RootBuildCache $cache) => $cache->railpackPrepare(null, '4g', 1, 'acme/a:1', '/w/src', '/w/p:/etc')];
        yield 'registry with an option' => [fn (RootBuildCache $cache) => $cache->copyImageToLayout(TestNetwork::LOOPBACK.':5000,tls=enabled', 'acme/a:1', '/w/l', '1')];
        yield 'image ref that is an option' => [fn (RootBuildCache $cache) => $cache->copyImageToLayout(TestNetwork::LOOPBACK.':5000', '--help', '/w/l', '1')];
        yield 'layout dir with a colon' => [fn (RootBuildCache $cache) => $cache->copyImageToLayout(TestNetwork::LOOPBACK.':5000', 'acme/a:1', '/w/l:/etc', '1')];
        yield 'tag with a path' => [fn (RootBuildCache $cache) => $cache->copyImageToLayout(TestNetwork::LOOPBACK.':5000', 'acme/a:1', '/w/l', '1/../x')];
        yield 'tag that is an option' => [fn (RootBuildCache $cache) => $cache->copyImageToLayout(TestNetwork::LOOPBACK.':5000', 'acme/a:1', '/w/l', '-1')];
    }

    /** @param  \Closure(RootBuildCache): mixed  $build */
    #[DataProvider('refusedBuildCache')]
    public function test_a_build_cache_value_the_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(Privileged::buildCache());
    }

    /** @return list<array{name: string, sudo: list<string>, broker: list<string>}> */
    private function recorded(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 5).HostBrokerCases::FILE), true, flags: JSON_THROW_ON_ERROR);
    }
}
