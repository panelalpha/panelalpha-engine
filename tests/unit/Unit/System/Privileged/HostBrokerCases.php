<?php

namespace Tests\Unit\System\Privileged;

use App\Lib\Deploy\CacheManager\BuildCacheBuilder;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\ProjectCache;
use App\System\Privileged;
use Tests\Support\TestNetwork;

/** Broker requests for the cases in host-cases.json, which the broker's own tests plan. */
final class HostBrokerCases
{
    public const FILE = '/dockerfiles/root-broker/tests/host-cases.json';

    private const ENGINE = '/opt/panelalpha/shared-hosting';

    private const WORK = self::ENGINE.'/data/build-cache/0a1b2c3d4e5f';

    private const ACCEPT = 'application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    /**
     * @return list<array{name: string, broker: list<string>}>
     */
    public static function all(): array
    {
        $cases = [];
        foreach (self::calls() as $name => $call) {
            $cases[] = ['name' => $name, 'broker' => $call()];
        }

        return $cases;
    }

    /** @return iterable<string, \Closure(): list<string>> */
    private static function calls(): iterable
    {
        yield from self::host();
        yield from self::buildCache();
    }

    /** @return iterable<string, \Closure(): list<string>> */
    private static function host(): iterable
    {
        $h = static fn () => Privileged::host();
        $docroots = [
            'shop' => ['home' => '/home/shop', 'docroot' => '/home/shop/shop.example.com/public_html'],
            'blog' => ['home' => '/home/blog', 'docroot' => "/home/blog/it's here"],
        ];

        yield from self::accountDockerCleanup();
        yield 'applyBuildNetworkFirewall' => fn () => BuildNetwork::firewallArgv('panelalpha-build');
        yield 'applyTenantNetworkFirewall' => fn () => TenantNetwork::firewallArgv();
        yield 'ensureOwnImage' => fn () => $h()->ensureOwnImage('panelalpha/php:8.3-cli-bookworm-pa1234abcd');
        yield 'refreshPublicImage' => fn () => $h()->refreshPublicImage('node:22-bookworm-slim');
        yield 'projectStats' => fn () => $h()->projectStats(['shop', 'blog'], 45);
        yield 'projectDisk' => fn () => $h()->projectDisk(['shop' => '/home/shop']);
        yield 'projectDisk, two homes' => fn () => $h()->projectDisk(['shop' => '/home/shop', 'blog' => '/home/blog/']);
        yield 'classicAppTypes' => fn () => $h()->classicAppTypes($docroots);
        yield 'edgeProbe' => fn () => $h()->edgeProbe('shop.example.com', TestNetwork::public(7), 4, true);
        yield 'edgeProbe, plain http over IPv6' => fn () => $h()->edgeProbe('xn--bcher-kva.example', '2001:db8::7', 0, false);
        yield 'lxcfsFiles' => fn () => $h()->lxcfsFiles();
        yield 'prepareProjectCache' => fn () => (new DindHostBuilder)->prepareCacheArgv(new EngineAccount('alice', '/home/alice', '1001:1002'));
        yield 'pruneProjectCaches' => fn () => ProjectCache::pruneArgv(86400);
        yield 'pruneProjectCaches, dry run skipping' => fn () => ProjectCache::pruneArgv(3600, true, ['alice', 'bob']);
        yield 'httpRequest' => fn () => CacheRegistry::hostArgv('GET', 'http://'.TestNetwork::LOOPBACK.':5000/v2/_catalog?n=1000', ['Accept' => 'application/json']);
        yield 'httpRequest, HEAD' => fn () => CacheRegistry::hostArgv('HEAD', 'http://'.TestNetwork::LOOPBACK.':5000/v2/acme/app/manifests/v1', ['Accept' => self::ACCEPT]);
        yield 'httpRequest, DELETE' => fn () => CacheRegistry::hostArgv('DELETE', 'http://'.TestNetwork::LOOPBACK.':5000/v2/acme/app/manifests/sha256:'.str_repeat('ab', 32), []);
        yield 'requestEngineCertificate' => fn () => $h()->requestEngineCertificate(self::ENGINE, [], []);
        yield 'requestEngineCertificate, every value' => fn () => $h()->requestEngineCertificate(
            self::ENGINE,
            ['domain' => 'Panel-1.Example.com', 'email' => 'ops+tls@example.com', 'ip' => '2001:db8::1'],
            ['dry-run', 'staging', 'skip-dns-check', 'force-renewal'],
        );
        yield 'requestEngineCertificate, some' => fn () => $h()->requestEngineCertificate(self::ENGINE, ['ip' => TestNetwork::public(4), 'email' => ''], ['staging']);
        yield 'detectStuckDind' => fn () => $h()->detectStuckDind();
        yield 'recoverStuckDind' => fn () => $h()->recoverStuckDind(true, true);
        yield 'recoverStuckDind, no flags' => fn () => $h()->recoverStuckDind(false, false);
        yield 'recoverDindAccount' => fn () => $h()->recoverDindAccount('alice', true, false);
        yield 'enqueueEngineUpdate' => fn () => $h()->enqueueEngineUpdate(self::ENGINE, "LIC-it's-1");
        yield 'enqueueEngineUpdate, no key' => fn () => $h()->enqueueEngineUpdate(self::ENGINE, null);
        yield 'enqueueEngineUpdate, key 0' => fn () => $h()->enqueueEngineUpdate(self::ENGINE, '0');
        yield 'seedEngineUpdatePlaceholder' => fn () => $h()->seedEngineUpdatePlaceholder();
        yield 'recordEngineUpdateRefusal' => fn () => $h()->recordEngineUpdateRefusal("Can't open /var/run/atd.pid to signal atd. No atd running?\n", 1);
        yield 'changeWebserver' => fn () => $h()->changeWebserver(self::ENGINE, 'nginx-proxy', null);
        yield 'changeWebserver, serial' => fn () => $h()->changeWebserver(self::ENGINE, 'litespeed', 'SER-1a2B');
        yield 'processExists' => fn () => $h()->processExists(4321);
    }

    /** @return iterable<string, \Closure(): list<string>> */
    private static function accountDockerCleanup(): iterable
    {
        foreach ([[
            'alice', '/home/alice', ['mysql:8.4', 'redis:7'],
        ], [
            'my.shop', '/home/my.shop/', [],
        ]] as [$username, $home, $refs]) {
            $argvs = DindAccountCleanup::hostCleanupArgvs($username, $home, $refs);
            $suffix = $refs === [] ? ', no images' : '';
            yield 'removeAccountDockerStore'.$suffix => static fn () => $argvs[0];
            yield 'removeAccountDockerFiles'.$suffix => static fn () => $argvs[1];
        }
    }

    /** @return iterable<string, \Closure(): list<string>> */
    private static function buildCache(): iterable
    {
        $builder = new BuildCacheBuilder('panelalpha-build');
        $build = ['context' => 'docker/app', 'dockerfile' => 'Dockerfile.prod', 'target' => 'runtime', 'args' => ['NODE_ENV' => 'production', 'NOTE' => "it's\nlong"]];
        $plain = ['context' => '', 'dockerfile' => 'Dockerfile', 'target' => null, 'args' => []];
        $layouts = [
            'acme/base:1.2' => ['dir' => self::WORK.'/layout-0a1b2c3d', 'digest' => 'sha256:'.str_repeat('0a', 32)],
            'ghcr.io/acme/tools' => ['dir' => self::WORK.'/layout-4e5f6a7b', 'digest' => 'sha256:'.str_repeat('7b', 32)],
        ];

        yield 'buildKit' => fn () => $builder->buildArgv(self::WORK.'/src', self::WORK.'/out-0a1b2c3d', $build, $layouts);
        yield 'buildKit, plain Dockerfile' => fn () => $builder->buildArgv(self::WORK.'/src', self::WORK.'/out-0a1b2c3d', $plain);
        yield 'buildKit, default network' => fn () => (new BuildCacheBuilder(null))->buildArgv(self::WORK.'/src', self::WORK.'/out-ffffffff', $plain);
        yield 'buildKit, railpack' => fn () => $builder->railpackBuildArgv(self::WORK.'/src', self::WORK.'/plan', self::WORK.'/out-0a1b2c3d');
        yield 'railpackPrepare' => fn () => $builder->railpackPrepareArgv('ghcr.io/panelalpha/engine-user-dind:v2.2.0-20261028', self::WORK.'/src', self::WORK.'/plan');
        yield 'copyImageToLayout' => fn () => $builder->exportLayoutArgv('acme/base:1.2', self::WORK.'/layout-0a1b2c3d');
        yield 'copyImageToLayout, no tag' => fn () => $builder->exportLayoutArgv('ghcr.io/acme/tools', self::WORK.'/layout-4e5f6a7b');
    }
}
