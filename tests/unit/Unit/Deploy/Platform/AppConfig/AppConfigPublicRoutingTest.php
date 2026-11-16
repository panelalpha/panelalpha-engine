<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Project\PublicRouting\PublicPort;
use App\Lib\Project\PublicRouting\PublicRouting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `domain:` and `public_ports:` as panelalpha.yaml writes them; what depends on the server is decided at deploy.
 */
class AppConfigPublicRoutingTest extends TestCase
{
    public function test_a_file_without_either_key_asks_for_nothing(): void
    {
        $config = AppConfig::fromYaml("env:\n  MODE: prod\n");

        $this->assertNotNull($config);
        $this->assertTrue($config->publicRouting()->isEmpty());
    }

    public function test_domain_host_and_ports_are_read(): void
    {
        $config = AppConfig::fromYaml(<<<'YAML'
            domain: host
            public_ports:
              - { listen: 8081, to: 3000, transport: tcp }
              - { listen: 27015, transport: udp }
              - 25565
            YAML);

        $routing = $config?->publicRouting();
        $this->assertNotNull($routing);
        $this->assertTrue($routing->wantsHostDomain());
        $this->assertSame([
            ['listen' => 8081, 'to' => 3000, 'transport' => 'tcp'],
            ['listen' => 27015, 'to' => 27015, 'transport' => 'udp'],
            ['listen' => 25565, 'to' => 25565, 'transport' => 'tcp'],
        ], $routing->toArray()['ports']);
    }

    public function test_only_the_routing_keys_still_make_an_app_config(): void
    {
        $config = AppConfig::fromYaml("domain: host\n");

        $this->assertNotNull($config);
        $this->assertFalse($config->isEmpty());
        $this->assertNull($config->manifest(), 'routing keys describe no platform');
    }

    public function test_the_same_port_on_tcp_and_udp_is_two_listeners(): void
    {
        $routing = AppConfig::fromYaml("public_ports:\n  - { listen: 53, transport: tcp }\n  - { listen: 53, transport: udp }\n")?->publicRouting();

        $this->assertSame(['tcp/53', 'udp/53'], array_map(static fn (PublicPort $port): string => $port->key(), $routing?->ports ?? []));
    }

    public function test_what_a_deploy_records_reads_back_the_same(): void
    {
        $routing = new PublicRouting(PublicRouting::DOMAIN_HOST, [new PublicPort(8081, 3000), new PublicPort(27015, 27015, PublicPort::UDP)]);

        $this->assertEquals($routing, PublicRouting::fromArray(json_decode((string) json_encode($routing->toArray()), true)));
        $this->assertTrue(PublicRouting::fromArray('garbage')->isEmpty());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusals(): array
    {
        return [
            'unknown domain' => ["domain: shop.example.com\n", "panelalpha.yaml: 'domain' must be one of generated, host"],
            'ports not a list' => ["public_ports: {listen: 8081}\n", "panelalpha.yaml: 'public_ports' must be a list of {listen, to, transport}"],
            'unknown entry key' => ["public_ports:\n  - { listen: 8081, host: x }\n", 'panelalpha.yaml: public_ports[0] must be {listen: <port>, to: <port>, transport: tcp|udp}'],
            'no listen' => ["public_ports:\n  - { to: 3000 }\n", 'panelalpha.yaml: public_ports[0].listen must be a port number, 1-65535'],
            'listen out of range' => ["public_ports:\n  - 70000\n", 'panelalpha.yaml: public_ports[0].listen must be a port number, 1-65535'],
            'to a string' => ["public_ports:\n  - { listen: 8081, to: '3000' }\n", 'panelalpha.yaml: public_ports[0].to must be a port number, 1-65535'],
            'transport http' => ["public_ports:\n  - { listen: 8081, transport: http }\n", 'panelalpha.yaml: public_ports[0].transport must be one of tcp, udp'],
            'the webserver port' => ["public_ports:\n  - 443\n", "panelalpha.yaml: public_ports[0].listen 443 is the webserver's own: HTTP reaches the app through its domain and `port`"],
            'an engine port' => ["public_ports:\n  - 2011\n", 'panelalpha.yaml: public_ports[0].listen 2011 is reserved by the engine'],
            'twice' => ["public_ports:\n  - 8081\n  - { listen: 8081, to: 9000 }\n", "panelalpha.yaml: 'public_ports' lists tcp port 8081 twice"],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_mistake_in_the_file_fails_when_it_is_read(string $yaml, string $message): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage($message);

        AppConfig::fromYaml($yaml);
    }

    public function test_a_markdown_page_carries_it_in_its_embedded_config(): void
    {
        $config = AppConfig::fromMarkdown("# App\n\n`panelalpha.yaml`\n```yaml\ndomain: host\n```\n");

        $this->assertTrue($config?->publicRouting()->wantsHostDomain());
    }

    public function test_a_directory_carries_it_from_its_yaml(): void
    {
        $config = AppConfig::fromDirectory(['config' => "public_ports:\n  - 8081\n"]);

        $this->assertSame(8081, $config?->publicRouting()->ports[0]->listen);
    }
}
