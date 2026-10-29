<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Compose\ServiceHardener;
use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * Publishing `8080:8080` on loopback only is a security default on a shared
 * machine. Inside an account container it only means the app cannot be
 * reached, so hardening publishes it properly -- which is also what makes the
 * port visible to detection at all.
 */
final class LoopbackPublishedPortTest extends TestCase
{
    #[DataProvider('loopbackBindings')]
    public function testALoopbackHostIsDropped(string $binding, string $expected): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [$binding]]);

        $this->assertSame([$expected], $hardened['ports']);
    }

    /** @return array<string, list<string>> */
    public static function loopbackBindings(): array
    {
        return [
            'ipv4' => [TestNetwork::LOOPBACK . ':53842:53842', '53842:53842'],
            'ipv6' => ['[::1]:8080:80', '8080:80'],
            'by name' => ['localhost:3000:3000', '3000:3000'],
            'with a protocol' => [TestNetwork::LOOPBACK . ':8080:80/tcp', '8080:80/tcp'],
        ];
    }

    #[DataProvider('ipv6WildcardBindings')]
    public function testAnIpv6WildcardHostIsDropped(string $binding, string $expected): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [$binding]]);

        $this->assertSame([$expected], $hardened['ports']);
    }

    /** @return array<string, list<string>> */
    public static function ipv6WildcardBindings(): array
    {
        return [
            'short form' => ['[::]:8080:80', '8080:80'],
            'with a protocol' => ['[::]:8080:80/tcp', '8080:80/tcp'],
        ];
    }

    public function testTheLongFormLosesAnIpv6WildcardHostIp(): void
    {
        $hardened = ServiceHardener::harden('app', [
            'image' => 'app',
            'ports' => [['target' => 80, 'published' => '8080', 'host_ip' => '::']],
        ]);

        $this->assertSame([['target' => 80, 'published' => '8080']], $hardened['ports']);
    }

    /** IPv4 already has the port: publishing `[::]` everywhere too would clash with it. */
    public function testAnIpv6WildcardBesideAnIpv4PublishOfThePortStays(): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [TestNetwork::ANY . ':8080:80', '[::]:8080:80']]);

        $this->assertSame([TestNetwork::ANY . ':8080:80', '[::]:8080:80'], $hardened['ports']);
    }

    #[DataProvider('bindingsToLeaveAlone')]
    public function testEverythingElseIsUntouched(string $binding): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [$binding]]);

        $this->assertSame([$binding], $hardened['ports']);
    }

    /** @return array<string, list<string>> */
    public static function bindingsToLeaveAlone(): array
    {
        return [
            'container port only' => ['8080'],
            'host and container' => ['8080:80'],
            // An address the operator chose deliberately is theirs to keep.
            'a real address' => [TestNetwork::private(5) . ':8080:80'],
            'all interfaces' => [TestNetwork::ANY . ':8080:80'],
        ];
    }

    public function testTheLongFormLosesItsLoopbackHostIp(): void
    {
        $hardened = ServiceHardener::harden('app', [
            'image' => 'app',
            'ports' => [['target' => 80, 'published' => '8080', 'host_ip' => TestNetwork::LOOPBACK]],
        ]);

        $this->assertSame([['target' => 80, 'published' => '8080']], $hardened['ports']);
    }

    public function testALongFormHostIpThatIsNotLoopbackStays(): void
    {
        $port = ['target' => 80, 'published' => '8080', 'host_ip' => TestNetwork::private(5)];
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [$port]]);

        $this->assertSame([$port], $hardened['ports']);
    }

    public function testAServiceWithNoPortsIsUnchanged(): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app']);

        $this->assertArrayNotHasKey('ports', $hardened);
    }

    /**
     * The point of the rewrite: Gokapi published only to loopback, so the scan
     * found nothing and detection fell back to a platform default port that
     * nothing was listening on.
     */
    public function testTheRewrittenPortIsVisibleToTheScan(): void
    {
        $dir = sys_get_temp_dir() . '/pa-loopback-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $compose = $dir . '/docker-compose.yml';

        try {
            file_put_contents($compose, "services:\n  gokapi:\n    image: f0rc3/gokapi:latest\n"
                . "    ports:\n      - '" . TestNetwork::LOOPBACK . ":53842:53842'\n");
            // 8080 is the platform fallback: the scan found no published port
            // at all, which is exactly how Gokapi ended up proxied to a port
            // nothing was listening on.
            $this->assertSame(8080, ComposePortScan::primaryOf($compose), 'as the repo ships it');

            $hardened = ServiceHardener::harden('gokapi', [
                'image' => 'f0rc3/gokapi:latest',
                'ports' => [TestNetwork::LOOPBACK . ':53842:53842'],
            ]);
            file_put_contents(
                $compose,
                "services:\n  gokapi:\n    image: f0rc3/gokapi:latest\n"
                . "    ports:\n      - '" . $hardened['ports'][0] . "'\n"
            );

            $this->assertSame(53842, ComposePortScan::primaryOf($compose), 'after hardening');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * RSS Monster templates its bind address. Splitting the raw string counts
     * five fields instead of three, so the loopback binding survived and the
     * app published a port nothing outside the container could reach.
     */
    #[DataProvider('templatedBindings')]
    public function testAVariableDefaultIsResolvedBeforeDeciding(string $binding, string $expected): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [$binding]]);

        $this->assertSame([$expected], $hardened['ports']);
    }

    /** @return array<string, list<string>> */
    public static function templatedBindings(): array
    {
        return [
            'rss monster' => [
                '${RSSMONSTER_BIND_ADDRESS:-' . TestNetwork::LOOPBACK . '}:${RSSMONSTER_PORT:-3000}:3000',
                // The ports stay as written, so an operator still picks the port.
                '${RSSMONSTER_PORT:-3000}:3000',
            ],
            'host only' => ['${BIND:-' . TestNetwork::LOOPBACK . '}:8080:80', '8080:80'],
            'the dash form' => ['${BIND-localhost}:8080:80', '8080:80'],
        ];
    }

    #[DataProvider('templatedBindingsToLeaveAlone')]
    public function testATemplatedNonLoopbackBindingIsUntouched(string $binding): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => [$binding]]);

        $this->assertSame([$binding], $hardened['ports']);
    }

    /** @return array<string, list<string>> */
    public static function templatedBindingsToLeaveAlone(): array
    {
        return [
            // An address the operator chose is theirs, resolved or not.
            'a real address' => ['${BIND:-' . TestNetwork::private(5) . '}:8080:80'],
            'all interfaces' => ['${BIND:-' . TestNetwork::ANY . '}:8080:80'],
            // Mailcow's shape: an empty default is already every interface.
            'empty default' => ['${HTTPS_BIND:-}:${HTTPS_PORT:-443}:443'],
            'templated ports only' => ['${PORT:-3000}:3000'],
        ];
    }

    /**
     * Poznote's MCP sidecar is loopback on purpose next to the web
     * server, whose `${HTTP_WEB_PORT}:80` has no default. The sidecar became the site.
     */
    public function testALoopbackSidecarBesideAWebServiceStaysLoopback(): void
    {
        $hardened = ComposeHarden::apply(['services' => [
            'webserver' => ['image' => 'ghcr.io/timothepoznanski/poznote', 'ports' => ['${HTTP_WEB_PORT}:80']],
            'mcp-server' => ['image' => 'ghcr.io/timothepoznanski/poznote-mcp', 'ports' => [TestNetwork::LOOPBACK . ':${POZNOTE_MCP_PORT:-8045}:8045']],
        ]]);

        $this->assertSame(['${HTTP_WEB_PORT:-80}:80'], $hardened['services']['webserver']['ports']);
        $this->assertSame([TestNetwork::LOOPBACK . ':${POZNOTE_MCP_PORT:-8045}:8045'], $hardened['services']['mcp-server']['ports']);
        $this->assertSame(['all' => [80], 'primary' => 80, 'refused' => []], ComposePortScan::ofParsed($hardened));
    }

    /** A datastore publishing its port is no front door: the app's loopback binding still opens. */
    public function testALoopbackAppBesideAPublishedDatabaseIsStillOpened(): void
    {
        $hardened = ComposeHarden::apply(['services' => [
            'app' => ['image' => 'acme/app', 'ports' => [TestNetwork::LOOPBACK . ':3000:3000']],
            'db' => ['image' => 'postgres:16', 'ports' => ['5432:5432']],
        ]]);

        $this->assertSame(['3000:3000'], $hardened['services']['app']['ports']);
    }

    public function testAnUnsetHostPortVariableDefaultsToTheContainerPort(): void
    {
        $hardened = ServiceHardener::harden('app', ['image' => 'app', 'ports' => ['${PORT:-8080}:80', '$WEB:3000/tcp']]);

        $this->assertSame(['${PORT:-8080}:80', '${WEB:-3000}:3000/tcp'], $hardened['ports']);
    }
}
