<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceHardener;
use PHPUnit\Framework\TestCase;

/**
 * What a service from someone else's compose file is allowed to ask for.
 *
 * Tenant stacks run in a Docker-in-Docker account, and the keys stripped here
 * are the ones that reach past it: a mounted Docker socket is root on the
 * account's daemon, `privileged` and `pid: host` are the standard container
 * escapes, and `network_mode: host` puts the service on the account's network
 * namespace. None of them fail loudly if they survive - the stack starts, and
 * the isolation is simply gone.
 *
 * The limits are the other half: a stack with no memory or pid cap is one
 * fork bomb away from taking the host down for every other account on it.
 */
class ServiceHardenerTest extends TestCase
{
    public function test_the_container_escapes_are_stripped(): void
    {
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app',
            'privileged' => true,
            'pid' => 'host',
            'ipc' => 'host',
            'uts' => 'host',
            'devices' => ['/dev/kvm:/dev/kvm'],
        ]);

        foreach (['privileged', 'pid', 'ipc', 'uts', 'devices'] as $key) {
            $this->assertArrayNotHasKey($key, $service, $key);
        }
    }

    public function test_host_networking_is_stripped(): void
    {
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'network_mode' => 'host']);

        $this->assertArrayNotHasKey('network_mode', $service);
    }

    public function test_another_network_mode_is_left_alone(): void
    {
        // `service:db` and `none` are legitimate and confer nothing.
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'network_mode' => 'none']);

        $this->assertSame('none', $service['network_mode']);
    }

    public function test_a_mounted_docker_socket_is_removed(): void
    {
        // Root on the account's daemon, which is the whole isolation boundary.
        foreach ([
            '/var/run/docker.sock:/var/run/docker.sock',
            '/var/run/docker.sock:/var/run/docker.sock:ro',
            '/run/docker.sock:/run/docker.sock',
            './docker.sock:/var/run/docker.sock',
            '/var/run/docker.sock',
        ] as $mount) {
            $service = ServiceHardener::harden('app', [
                'image' => 'acme/app',
                'volumes' => [$mount, 'data:/data'],
            ]);

            $this->assertSame(['data:/data'], $service['volumes'], $mount);
        }
    }

    public function test_a_long_form_socket_mount_is_removed(): void
    {
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app',
            'volumes' => [
                ['type' => 'bind', 'source' => '/var/run/docker.sock', 'target' => '/var/run/docker.sock'],
                ['type' => 'volume', 'source' => 'data', 'target' => '/data'],
            ],
        ]);

        $this->assertCount(1, $service['volumes']);
        $this->assertSame('data', $service['volumes'][0]['source']);
    }

    public function test_an_ordinary_mount_that_merely_mentions_a_socket_survives(): void
    {
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app',
            'volumes' => ['sockets:/app/sockets', './my-docker.sock.bak:/backup/docker.sock.bak'],
        ]);

        $this->assertCount(2, $service['volumes']);
    }

    public function test_the_kept_volumes_are_reindexed(): void
    {
        // A gap serialises as a YAML map where compose wants a sequence.
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app',
            'volumes' => ['/var/run/docker.sock:/var/run/docker.sock', 'data:/data'],
        ]);

        $this->assertSame([0], array_keys($service['volumes']));
    }

    public function test_a_service_that_would_never_restart_is_given_a_policy(): void
    {
        foreach ([[], ['restart' => ''], ['restart' => false]] as $extra) {
            $service = ServiceHardener::harden('app', ['image' => 'acme/app'] + $extra);

            $this->assertSame('unless-stopped', $service['restart']);
        }
    }

    public function test_the_projects_own_restart_policy_is_respected(): void
    {
        // `on-failure` and `no` are deliberate choices - a one-shot migration
        // service restarted forever is worse than one that stops.
        $this->assertSame('no', ServiceHardener::harden('migrate', ['image' => 'acme/app', 'restart' => 'no'])['restart']);
    }

    public function test_a_service_with_no_memory_limit_is_given_one(): void
    {
        $this->assertSame('384m', ServiceHardener::harden('app', ['image' => 'acme/app'])['mem_limit']);
        $this->assertSame('512m', ServiceHardener::harden('db', ['image' => 'postgres:16'])['mem_limit']);
    }

    public function test_a_limit_the_project_set_is_respected(): void
    {
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'mem_limit' => '1g']);

        $this->assertSame('1g', $service['mem_limit']);
    }

    public function test_a_reservation_counts_as_the_project_having_decided(): void
    {
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'mem_reservation' => '256m']);

        $this->assertArrayNotHasKey('mem_limit', $service);
    }

    public function test_deploy_resources_become_the_limits_this_class_speaks(): void
    {
        // Both forms on one service is not a style question: Compose rejects
        // the whole project with "can't set distinct values on 'pids_limit'
        // and 'deploy.resources.limits.pids'", and OpenCart's compose sizes
        // every service that way. The author's numbers are kept; the block
        // that cannot coexist with them is not.
        $service = ServiceHardener::harden('db', [
            'image' => 'mariadb',
            'deploy' => [
                'replicas' => 1,
                'resources' => [
                    'limits' => ['memory' => '512M', 'cpus' => '0.5'],
                    'reservations' => ['memory' => '256M'],
                ],
            ],
        ]);

        $this->assertSame(['replicas' => 1], $service['deploy']);
        $this->assertSame('512M', $service['mem_limit']);
        $this->assertSame('256M', $service['mem_reservation']);
        $this->assertSame('0.5', $service['cpus']);
        $this->assertSame(1024, $service['pids_limit']);
    }

    public function test_a_deploy_block_with_nothing_left_in_it_is_removed(): void
    {
        $service = ServiceHardener::harden('db', [
            'image' => 'mariadb',
            'deploy' => ['resources' => ['limits' => ['memory' => '512M']]],
        ]);

        $this->assertArrayNotHasKey('deploy', $service);
    }

    public function test_every_service_gets_a_process_limit(): void
    {
        // The fork-bomb cap. Nothing else in the account bounds process count.
        // 1024, not 256: the lower cap starved multi-daemon images (engine#220).
        $this->assertSame(1024, ServiceHardener::harden('app', ['image' => 'acme/app'])['pids_limit']);
    }

    public function test_a_process_limit_the_project_set_is_respected(): void
    {
        // A value distinct from the default, so this proves preservation, not
        // that both happen to be 1024.
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'pids_limit' => 4096]);

        $this->assertSame(4096, $service['pids_limit']);
    }

    public function test_cpu_shares_are_capped_lower_for_a_database(): void
    {
        // A database under load would otherwise starve the app it serves.
        $this->assertSame('0.50', ServiceHardener::harden('db', ['image' => 'postgres:16'])['cpus']);
        $this->assertSame('0.75', ServiceHardener::harden('app', ['image' => 'acme/app'])['cpus']);
    }

    public function test_a_cpu_setting_the_project_made_is_respected(): void
    {
        $this->assertSame('2', ServiceHardener::harden('app', ['image' => 'acme/app', 'cpus' => '2'])['cpus']);
        $this->assertArrayNotHasKey(
            'cpus',
            ServiceHardener::harden('app', ['image' => 'acme/app', 'cpu_count' => 2])
        );
    }

    public function test_a_node_service_gets_a_heap_cap(): void
    {
        // Without it Node reads the host's memory, not the container's, and
        // the kernel kills it before V8 ever collects.
        $service = ServiceHardener::harden('app', ['image' => 'node:20-alpine']);

        $this->assertSame('--max-old-space-size=268', $service['environment']['NODE_OPTIONS']);
    }

    public function test_the_heap_cap_follows_the_containers_own_limit(): void
    {
        $service = ServiceHardener::harden('app', ['image' => 'node:20', 'mem_limit' => '1g']);

        $this->assertSame('--max-old-space-size=716', $service['environment']['NODE_OPTIONS']);
    }

    public function test_a_node_service_is_recognised_by_its_command(): void
    {
        $service = ServiceHardener::harden('worker', [
            'image' => 'acme/app',
            'command' => ['npm', 'run', 'queue'],
        ]);

        $this->assertArrayHasKey('NODE_OPTIONS', $service['environment']);
    }

    public function test_an_unidentifiable_service_gets_the_cap_anyway(): void
    {
        // A build with no image says nothing about its runtime. The cap is
        // harmless to a non-Node process and the omission is not.
        $service = ServiceHardener::harden('app', ['build' => '.']);

        $this->assertArrayHasKey('NODE_OPTIONS', $service['environment']);
    }

    public function test_a_known_non_node_runtime_is_left_alone(): void
    {
        foreach (['nginx:alpine', 'php:8.3-fpm', 'python:3.12', 'ruby:3.3', 'golang:1.22'] as $image) {
            $service = ServiceHardener::harden('app', ['image' => $image]);

            $this->assertArrayNotHasKey('environment', $service, $image);
        }
    }

    public function test_a_database_never_gets_a_node_heap_cap(): void
    {
        $service = ServiceHardener::harden('db', ['image' => 'postgres:16']);

        $this->assertArrayNotHasKey('environment', $service);
    }

    public function test_node_options_the_project_set_are_never_overwritten(): void
    {
        // A project that has tuned its own heap knows more than the default.
        $service = ServiceHardener::harden('app', [
            'image' => 'node:20',
            'environment' => ['NODE_OPTIONS' => '--max-old-space-size=2048'],
        ]);

        $this->assertSame('--max-old-space-size=2048', $service['environment']['NODE_OPTIONS']);
    }

    public function test_the_heap_cap_is_appended_in_the_projects_own_form(): void
    {
        $service = ServiceHardener::harden('app', [
            'image' => 'node:20',
            'environment' => ['APP_ENV=production'],
        ]);

        $this->assertSame(['APP_ENV=production', 'NODE_OPTIONS=--max-old-space-size=268'], $service['environment']);
    }
}
