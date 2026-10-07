<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\System\Project\Dind\Generation\NextGeneration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The second generation of a redeploy is the routed service once
 * more, beside the running one, sharing its sidecars, networks and volumes.
 */
class NextGenerationTest extends TestCase
{
    /** `docker compose config --format json` of a bind-mounted Node app, as the account prints it. */
    private const MOUNTED_NODE = [
        'name' => 'project',
        'networks' => ['default' => ['name' => 'project_default', 'ipam' => []]],
        'services' => [
            'app' => [
                'image' => 'node:22-bookworm-slim',
                'labels' => ['panelalpha.generated' => 'framework-recipe'],
                'networks' => ['default' => null],
                'volumes' => [
                    ['type' => 'bind', 'source' => '/home/acme/project', 'target' => '/app', 'bind' => []],
                    ['type' => 'bind', 'source' => '/var/lib/lxcfs/proc/meminfo', 'target' => '/proc/meminfo', 'read_only' => true],
                ],
                'ports' => [['mode' => 'ingress', 'target' => 3000, 'published' => '3000', 'protocol' => 'tcp']],
            ],
        ],
    ];

    public function test_the_generation_project_is_named_after_the_running_one(): void
    {
        $next = NextGeneration::plan(self::MOUNTED_NODE, 3000, false);

        $this->assertInstanceOf(NextGeneration::class, $next);
        $this->assertSame('project-next', $next->projectName());
        $this->assertSame('app', $next->service);
        $this->assertSame([3000 => 3000], $next->ports);
    }

    public function test_the_override_leaves_the_port_to_docker_and_shares_the_network(): void
    {
        $next = NextGeneration::plan(self::MOUNTED_NODE, 3000, false);
        $override = Yaml::parse($next->override(), Yaml::PARSE_CUSTOM_TAGS);

        $ports = $override['services']['app']['ports'];
        $this->assertSame('override', $ports->getTag());
        $this->assertSame([['target' => 3000, 'protocol' => 'tcp']], $ports->getValue());
        $this->assertSame(['panelalpha.generation' => 'next'], $override['services']['app']['labels']);
        $this->assertArrayNotHasKey('image', $override['services']['app'], 'a stock image needs no tag');

        $network = $override['networks']['default'];
        $this->assertSame('override', $network->getTag());
        $this->assertSame(['name' => 'project_default', 'external' => true], $network->getValue());
        $this->assertStringContainsString('ports: !override', $next->override());
    }

    public function test_a_built_service_runs_the_image_the_build_tagged(): void
    {
        $config = self::MOUNTED_NODE;
        $config['services']['app']['build'] = ['context' => '/home/acme/project', 'dockerfile' => 'Dockerfile'];
        unset($config['services']['app']['image']);
        $config['services']['app']['volumes'] = [['type' => 'volume', 'source' => 'data', 'target' => '/data']];
        $config['volumes'] = ['data' => ['name' => 'project_data']];

        $override = Yaml::parse(NextGeneration::plan($config, 3000, false)->override(), Yaml::PARSE_CUSTOM_TAGS);

        $this->assertSame('project-app', $override['services']['app']['image']);
        $this->assertSame('reset', $override['services']['app']['build']->getTag());
        $this->assertSame(['name' => 'project_data', 'external' => true], $override['volumes']['data']->getValue());
    }

    public function test_sidecars_stay_and_procfile_processes_and_checkout_readers_are_replaced(): void
    {
        $config = self::MOUNTED_NODE;
        $config['services']['worker'] = ['image' => 'node:22', 'labels' => ['panelalpha.generated' => 'procfile-worker']];
        $config['services']['db'] = ['image' => 'postgres:16', 'volumes' => [['type' => 'volume', 'source' => 'pg', 'target' => '/var/lib/postgresql/data']]];
        $config['services']['proxy'] = ['image' => 'nginx', 'volumes' => [['type' => 'bind', 'source' => '/home/acme/project/nginx.conf', 'target' => '/etc/nginx/nginx.conf']]];

        $next = NextGeneration::plan($config, 3000, false);

        $this->assertSame(['app', 'worker', 'proxy'], $next->generationServices('/home/acme/project/'));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: bool, 2: string}> */
    public static function notSideBySide(): iterable
    {
        $base = self::MOUNTED_NODE;

        $other = $base;
        $other['services']['app']['ports'][0]['published'] = '8080';
        yield 'nothing publishes the routed port' => [$other, false, 'no service publishes port 3000'];

        $named = $base;
        $named['services']['app']['container_name'] = 'my-app';
        yield 'a fixed container name' => [$named, false, 'names its container (my-app)'];

        $host = $base;
        $host['services']['app']['network_mode'] = 'host';
        yield 'host networking' => [$host, false, 'network_mode: host'];

        $udp = $base;
        $udp['services']['app']['ports'][] = ['target' => 53, 'published' => '53', 'protocol' => 'udp'];
        yield 'a UDP port' => [$udp, false, 'a port range or a UDP port'];

        $range = $base;
        $range['services']['app']['ports'][] = ['target' => 9000, 'published' => '9000-9010', 'protocol' => 'tcp'];
        yield 'a port range' => [$range, false, 'a port range or a UDP port'];

        $stack = $base;
        $stack['services']['db'] = ['image' => 'postgres:16'];
        yield 'a repository stack of several services' => [$stack, true, 'runs 2 services'];

        yield 'an unreadable config' => [['services' => []], false, 'could not be read'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('notSideBySide')]
    public function test_what_cannot_run_twice_falls_back_and_says_why(array $config, bool $repositoryCompose, string $reason): void
    {
        $plan = NextGeneration::plan($config, 3000, $repositoryCompose);

        $this->assertIsString($plan);
        $this->assertStringContainsString($reason, $plan);
    }

    public function test_a_single_service_repository_stack_runs_twice(): void
    {
        $this->assertInstanceOf(NextGeneration::class, NextGeneration::plan(self::MOUNTED_NODE, 3000, true));
    }

    public function test_a_generated_app_with_sidecars_runs_twice(): void
    {
        $config = self::MOUNTED_NODE;
        $config['services']['redis'] = ['image' => 'redis:7', 'ports' => [['target' => 6379, 'published' => '6379', 'protocol' => 'tcp']]];

        $this->assertInstanceOf(NextGeneration::class, NextGeneration::plan($config, 3000, false));
    }
}
