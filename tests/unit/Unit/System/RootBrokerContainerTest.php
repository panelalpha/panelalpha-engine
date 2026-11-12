<?php

namespace Tests\Unit\System;

use App\Lib\Deploy\Dind\DindImageStore;
use App\System\Privileged;
use App\System\Privileged\Exchange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Core is an ordinary container; only root-broker holds the host, and the two share what the broker acts on. */
class RootBrokerContainerTest extends TestCase
{
    private const SOCKET_VOLUME = 'root-broker-socket:/run/panelalpha';

    private const EXCHANGE_VOLUME = 'core-exchange:/var/lib/panelalpha';

    /** @var array<string, mixed> */
    private array $services;

    protected function setUp(): void
    {
        $this->services = Yaml::parseFile(self::repo('docker-compose.yml'))['services'];
    }

    public function test_core_holds_no_privilege_and_no_host_handle(): void
    {
        $core = $this->services['core'];
        foreach (['privileged', 'pid', 'cap_add', 'devices', 'security_opt', 'network_mode', 'userns_mode'] as $key) {
            $this->assertArrayNotHasKey($key, $core);
        }
        foreach ($core['volumes'] as $volume) {
            $this->assertStringNotContainsString('docker.sock', $volume);
            $this->assertStringNotContainsString('/var/lib/docker', $volume);
        }
    }

    /** Core asks the broker for everything root; it runs no docker client of its own. */
    public function test_core_s_image_installs_no_docker_client(): void
    {
        $core = (string) file_get_contents(self::repo('dockerfiles/Dockerfile-core'));

        foreach (['docker-ce', 'containerd.io', 'docker-compose-plugin', 'download.docker.com'] as $docker) {
            $this->assertStringNotContainsString($docker, $core);
        }
    }

    public function test_the_broker_is_privileged_in_the_host_s_pid_namespace(): void
    {
        $broker = $this->services['root-broker'];
        $this->assertTrue($broker['privileged']);
        $this->assertSame('host', $broker['pid']);
        $this->assertSame('always', $broker['restart']);
        $this->assertContains('/var/run/docker.sock:/var/run/docker.sock', $broker['volumes']);
        $this->assertContains('root-broker', $this->services['core']['depends_on']);
    }

    public function test_the_broker_has_its_own_image_tagged_as_core_s_and_built_where_none_is_published(): void
    {
        $broker = $this->services['root-broker'];
        $tag = explode(':', $this->services['core']['image'], 2)[1];

        $this->assertSame('ghcr.io/panelalpha/engine-root-broker:'.$tag, $broker['image']);
        $this->assertSame(['context' => './dockerfiles', 'dockerfile' => 'Dockerfile-root-broker'], $broker['build']);
        $this->assertSame('missing', $broker['pull_policy']);
    }

    /** One base, so www-data is 33:33 in both and the socket's group means the same. */
    public function test_core_s_image_carries_only_the_client_and_the_broker_s_runs_only_the_broker(): void
    {
        $core = (string) file_get_contents(self::repo('dockerfiles/Dockerfile-core'));
        $broker = (string) file_get_contents(self::repo('dockerfiles/Dockerfile-root-broker'));

        $this->assertSame(self::baseImage($core), self::baseImage($broker));
        $this->assertStringContainsString('COPY root-broker/pa-root /usr/local/lib/panelalpha/', $core);
        foreach (['pa-root-broker', 'pa-broker', 'pa-file-stream', 'pa-root-owned', 'root-broker/rootbroker /'] as $brokerOnly) {
            $this->assertStringNotContainsString($brokerOnly, $core);
            $this->assertStringContainsString($brokerOnly, $broker);
        }
        $this->assertStringNotContainsString('php', $broker);
    }

    public function test_both_see_the_socket_the_exchange_and_core_s_own_paths_at_the_same_place(): void
    {
        foreach ([self::SOCKET_VOLUME, self::EXCHANGE_VOLUME, 'core-storage:/var/www/html/storage',
            './tests/coverage:/var/www/tests/coverage', '/home:/home', '/opt/panelalpha:/opt/panelalpha',
            '/etc/letsencrypt:/etc/letsencrypt', '/var/tmp/panelalpha-backup:/var/tmp/panelalpha-backup'] as $volume) {
            $this->assertContains($volume, $this->services['core']['volumes'], $volume);
            $this->assertContains($volume, $this->services['root-broker']['volumes'], $volume);
        }
        $this->assertContains('./core/resources/sources:/var/www/html/resources/sources:ro', $this->services['root-broker']['volumes']);
        $this->assertNotContains('./core:/var/www/html', $this->services['root-broker']['volumes']);
        $this->assertStringStartsWith('/var/lib/panelalpha/', Exchange::DIRECTORY);
        $this->assertStringStartsWith('/run/panelalpha/', DindImageStore::PUSH_LOCK);
    }

    public function test_only_core_and_the_broker_reach_the_socket_and_the_exchange(): void
    {
        $reaching = [];
        foreach ($this->services as $name => $service) {
            $this->assertArrayNotHasKey('volumes_from', $service, $name);
            foreach ($service['volumes'] ?? [] as $volume) {
                if (is_string($volume) && preg_match('~^(root-broker-socket|core-exchange):~', $volume) === 1) {
                    $reaching[] = $name;
                }
            }
        }

        $this->assertSame(['core', 'core', 'root-broker', 'root-broker'], $reaching);
    }

    public function test_metrics_mounts_only_the_storage_it_writes_and_the_homes_it_measures(): void
    {
        $this->assertSame(
            ['./scripts/server_metrics.py:/scripts/server_metrics.py', 'core-storage:/var/www/html/storage', '/home:/home:ro'],
            $this->services['metrics']['volumes']
        );
    }

    public function test_the_gateway_reads_a_refusal_by_the_brokers_own_exit_code(): void
    {
        $protocol = (string) file_get_contents(self::repo('dockerfiles/root-broker/rootbroker/protocol.py'));

        $this->assertStringContainsString('EXIT_REFUSED = '.Privileged::REFUSED."\n", $protocol);
    }

    public function test_nothing_core_starts_enters_the_host(): void
    {
        $started = [self::repo('dockerfiles/entrypoint-core.sh')];
        foreach (glob(self::repo('config/core/services').'/*/run') ?: [] as $run) {
            $started[] = $run;
        }
        $this->assertGreaterThan(1, count($started));
        foreach ($started as $file) {
            $this->assertStringNotContainsString('nsenter', (string) file_get_contents($file), $file);
            $this->assertStringNotContainsString('pa-root-broker', (string) file_get_contents($file), $file);
        }
    }

    private static function baseImage(string $dockerfile): string
    {
        return preg_match('/^FROM (\S+)/', $dockerfile, $match) === 1 ? $match[1] : '';
    }

    private static function repo(string $path): string
    {
        return dirname(__DIR__, 4).'/'.$path;
    }
}
