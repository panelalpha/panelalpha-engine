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

    public function test_the_keys_that_opt_out_of_the_accounts_limits_are_stripped(): void
    {
        $service = ServiceHardener::withoutEscapes([
            'image' => 'acme/app',
            'cgroupns_mode' => 'host',
            'runtime' => 'runc',
            'oom_kill_disable' => true,
            'oom_score_adj' => -1000,
            'storage_opt' => ['size' => '20G'],
        ]);

        $this->assertSame(['image' => 'acme/app'], $service);
    }

    public function test_binds_of_the_account_containers_own_filesystem_are_removed(): void
    {
        $service = ServiceHardener::withoutEscapes([
            'image' => 'acme/app',
            'volumes' => [
                '/home/acct/docker:/d',
                '/root:/r',
                '/usr/bin:/b',
                '/opt:/o',
                '/var/spool/cron:/c',
                '/run/service:/s',
                '~/docker:/d2',
                '${HOME}:/h',
                '../../../etc:/e',
                '../docker/volumes:/v',
                ['type' => 'bind', 'source' => '../../..', 'target' => '/all'],
                './data:/data',
                '.cache:/cache',
                '../.panelalpha/app/config.json:/config.json',
                '../:/account',
                'named:/named',
                '/srv/data:/srv',
            ],
        ]);

        $this->assertSame([
            './data:/data',
            '.cache:/cache',
            '../.panelalpha/app/config.json:/config.json',
            '../:/account',
            'named:/named',
            '/srv/data:/srv',
        ], $service['volumes']);
    }

    public function test_only_the_accounts_own_panelalpha_tree_is_bound_by_absolute_path(): void
    {
        $service = ServiceHardener::withoutEscapes([
            'image' => 'acme/app',
            'volumes' => [
                '/home/acct/.panelalpha:/state',
                '/home/acct/.panelalpha/trac:/data',
                ['type' => 'bind', 'source' => '/home/acct//.panelalpha/./conduit/data', 'target' => '/c'],
                '/home/acct/.panelalpha/../docker:/d',
                '/home/acct/.panelalpha/x/../../project:/p',
                '/home/acct/.panelalphax:/x',
                '/home/acct/project:/project',
                '/home/acct:/home',
                '/home/other/.panelalpha:/other',
                '/home/.panelalpha:/h',
                '/home:/all',
                '/root/.panelalpha/trac:/r',
                '/tmp/../home/acct/.panelalpha/ok:/ok',
                '/tmp/../etc:/e',
            ],
        ], accountUser: 'acct');

        $this->assertSame([
            '/home/acct/.panelalpha:/state',
            '/home/acct/.panelalpha/trac:/data',
            ['type' => 'bind', 'source' => '/home/acct//.panelalpha/./conduit/data', 'target' => '/c'],
            '/tmp/../home/acct/.panelalpha/ok:/ok',
        ], $service['volumes']);

        // Without an account nothing under /home is allowed.
        $this->assertArrayNotHasKey(
            'volumes',
            ServiceHardener::withoutEscapes(['volumes' => ['/home/acct/.panelalpha/trac:/data']])
        );
    }

    public function test_a_capability_beyond_the_default_set_is_stripped(): void
    {
        foreach ([['ALL'], ['SYS_ADMIN', 'SYS_PTRACE', 'SYS_MODULE'], 'ALL', []] as $caps) {
            $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'cap_add' => $caps]);

            $this->assertArrayNotHasKey('cap_add', $service, json_encode($caps));
        }
    }

    public function test_a_careful_privilege_drop_keeps_the_capabilities_it_adds_back(): void
    {
        // Drop everything, add back just enough to chown a data dir and switch
        // to a non-root user. Stripping cap_add left the entrypoint with none.
        $caps = ['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'];
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app',
            'cap_drop' => ['ALL'],
            'cap_add' => $caps,
        ]);

        $this->assertSame(['ALL'], $service['cap_drop']);
        $this->assertSame($caps, $service['cap_add']);
    }

    public function test_a_mixed_capability_list_keeps_only_the_default_ones(): void
    {
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app',
            'cap_add' => ['ALL', 'CAP_CHOWN', 'sys_admin', 'setuid', ' NET_BIND_SERVICE ', 'cap_net_admin', 42],
        ]);

        $this->assertSame(['CAP_CHOWN', 'setuid', 'NET_BIND_SERVICE', 'cap_net_admin'], $service['cap_add']);
    }

    public function test_net_admin_is_kept_for_binaries_that_carry_it_as_a_file_capability(): void
    {
        // NetAlertX: python3 has cap_net_admin+eip, and without it in the
        // bounding set execve fails with EPERM before any network work.
        $service = ServiceHardener::harden('netalertx', [
            'image' => 'ghcr.io/netalertx/netalertx:26.9.0',
            'cap_drop' => ['ALL'],
            'cap_add' => ['NET_ADMIN', 'NET_RAW', 'NET_BIND_SERVICE', 'CHOWN', 'SETUID', 'SETGID'],
        ]);

        $this->assertSame(
            ['NET_ADMIN', 'NET_RAW', 'NET_BIND_SERVICE', 'CHOWN', 'SETUID', 'SETGID'],
            $service['cap_add']
        );
    }

    public function test_a_single_capability_written_as_a_string_is_kept_as_a_list(): void
    {
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'cap_add' => 'NET_BIND_SERVICE']);

        $this->assertSame(['NET_BIND_SERVICE'], $service['cap_add']);
    }

    public function test_a_memlock_ulimit_an_account_cannot_grant_is_removed(): void
    {
        // Elasticsearch's documented setting; runc fails with "error setting
        // rlimit type 8" because the account's own limit is 8 MB.
        $service = ServiceHardener::harden('es', [
            'image' => 'elasticsearch:8.19.0',
            'ulimits' => ['memlock' => ['soft' => -1, 'hard' => -1], 'nofile' => 65536],
        ]);
        $this->assertSame(['nofile' => 65536], $service['ulimits']);

        $service = ServiceHardener::harden('es', ['image' => 'opensearch', 'ulimits' => ['memlock' => -1]]);
        $this->assertArrayNotHasKey('ulimits', $service);
    }

    public function test_every_service_may_ping_as_a_non_root_user(): void
    {
        // The account's daemon runs in a user namespace and skips Docker's
        // default ping_group_range, so `ping` as nagios failed.
        $service = ServiceHardener::harden('nagios', ['image' => 'manios/nagios:4.5.14']);

        $this->assertSame(['net.ipv4.ping_group_range' => '0 65535'], $service['sysctls']);
    }

    public function test_only_network_namespaced_sysctls_survive_in_either_form(): void
    {
        $map = ServiceHardener::withoutEscapes(['sysctls' => [
            'kernel.shm_rmid_forced' => 1,
            'vm.overcommit_memory' => 1,
            'net.ipv4.ip_unprivileged_port_start' => 80,
        ]]);
        $list = ServiceHardener::withoutEscapes(['sysctls' => [
            'kernel.domainname=x',
            'net.ipv4.ping_group_range=0 1000',
        ]]);

        $this->assertSame(['net.ipv4.ip_unprivileged_port_start' => 80], $map['sysctls']);
        $this->assertSame(['net.ipv4.ping_group_range=0 1000'], $list['sysctls']);
        $this->assertArrayNotHasKey('sysctls', ServiceHardener::withoutEscapes(['sysctls' => ['kernel.msgmax' => 1]]));
    }

    public function test_the_ping_range_is_added_beside_the_services_own_sysctls_and_never_over_them(): void
    {
        $list = ServiceHardener::harden('app', ['image' => 'a', 'sysctls' => ['net.ipv4.ip_unprivileged_port_start=0']]);
        $own = ServiceHardener::harden('app', ['image' => 'a', 'sysctls' => ['net.ipv4.ping_group_range' => '1000 1000']]);
        $shared = ServiceHardener::harden('app', ['image' => 'a', 'network_mode' => 'service:vpn']);

        $this->assertSame(
            ['net.ipv4.ip_unprivileged_port_start=0', 'net.ipv4.ping_group_range=0 65535'],
            $list['sysctls']
        );
        $this->assertSame(['net.ipv4.ping_group_range' => '1000 1000'], $own['sysctls']);
        $this->assertArrayNotHasKey('sysctls', $shared);
    }

    public function test_host_networking_is_stripped(): void
    {
        $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'network_mode' => 'host']);

        $this->assertArrayNotHasKey('network_mode', $service);
    }

    /** Hypermind ran on host networking with PORT=3000 and published nothing. */
    public function test_a_host_networked_service_publishes_its_port_env(): void
    {
        $service = ServiceHardener::harden('hypermind', [
            'image' => 'ghcr.io/lklynet/hypermind:latest',
            'network_mode' => 'host',
            'environment' => ['PORT=3000'],
        ]);

        $this->assertArrayNotHasKey('network_mode', $service);
        $this->assertSame(['3000:3000'], $service['ports']);

        $mapForm = ServiceHardener::harden('app', ['image' => 'a', 'network_mode' => 'host', 'environment' => ['PORT' => 8080]]);
        $this->assertSame(['8080:8080'], $mapForm['ports']);
    }

    public function test_a_host_networked_service_that_already_publishes_is_left_alone(): void
    {
        $service = ServiceHardener::harden('app', [
            'image' => 'acme/app', 'network_mode' => 'host', 'ports' => ['8000:8000'], 'environment' => ['PORT=3000'],
        ]);
        $this->assertSame(['8000:8000'], $service['ports']);

        $noPort = ServiceHardener::harden('app', ['image' => 'acme/app', 'network_mode' => 'host']);
        $this->assertArrayNotHasKey('ports', $noPort);
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

    /** The account container's boot scripts and runtime sockets are not the app's to mount. */
    public function test_binds_of_the_account_containers_boot_and_run_paths_are_removed(): void
    {
        foreach ([
            '/entrypoint.d:/x',
            '/entrypoint.sh:/x',
            '/run/service:/s',
            '/run/containerd:/c',
            '/var/run/service:/s',
        ] as $mount) {
            $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'volumes' => [$mount, 'data:/data']]);
            $this->assertSame(['data:/data'], $service['volumes'], $mount);
        }
        $long = ServiceHardener::withoutEscapes(['volumes' => [['type' => 'bind', 'source' => '/entrypoint.d', 'target' => '/e']]]);
        $this->assertArrayNotHasKey('volumes', $long);
    }

    /**
     * A named volume of the local driver mounts whatever `device:` says, and
     * the service only names the volume.
     */
    public function test_top_level_volumes_lose_driver_options_that_mount_a_path(): void
    {
        [$compose, $removed] = ServiceHardener::withoutHostPathEntries([
            'services' => ['app' => ['image' => 'alpine', 'volumes' => ['sock:/host-run', 'cache:/cache', 'data:/data']]],
            'volumes' => [
                'sock' => ['driver' => 'local', 'driver_opts' => ['type' => 'none', 'o' => 'bind', 'device' => '/var/run']],
                'lower' => ['driver_opts' => ['type' => 'overlay', 'o' => 'lowerdir=/etc,upperdir=/u,workdir=/w', 'device' => 'overlay']],
                'sneaky' => ['driver_opts' => ['type' => 'tmpfs', 'o' => 'bind', 'device' => '/']],
                'cache' => ['driver_opts' => ['type' => 'tmpfs', 'device' => 'tmpfs', 'o' => 'size=100m']],
                'data' => null,
                'plain' => ['labels' => ['a' => 'b']],
            ],
        ]);

        $this->assertSame(['driver' => 'local'], $compose['volumes']['sock']);
        $this->assertSame([], $compose['volumes']['lower']);
        $this->assertSame([], $compose['volumes']['sneaky']);
        $this->assertSame(['type' => 'tmpfs', 'device' => 'tmpfs', 'o' => 'size=100m'], $compose['volumes']['cache']['driver_opts']);
        $this->assertNull($compose['volumes']['data']);
        $this->assertSame(['labels' => ['a' => 'b']], $compose['volumes']['plain']);
        $this->assertSame(['volume sock: driver_opts', 'volume lower: driver_opts', 'volume sneaky: driver_opts'], $removed);
    }

    /** Compose bind-mounts a file-backed secret or config into the service. */
    public function test_secrets_and_configs_from_a_forbidden_file_are_removed(): void
    {
        [$compose, $removed] = ServiceHardener::withoutHostPathEntries([
            'services' => ['app' => ['image' => 'alpine', 'secrets' => ['s', 'ok']]],
            'secrets' => [
                's' => ['file' => '/var/run/docker.sock'],
                'ok' => ['file' => './secrets/db_password.txt'],
                'env' => ['environment' => 'DB_PASSWORD'],
            ],
            'configs' => ['c' => ['file' => '/etc/shadow'], 'nginx' => ['file' => './nginx.conf']],
        ]);

        $this->assertSame(['ok', 'env'], array_keys($compose['secrets']));
        $this->assertSame(['nginx'], array_keys($compose['configs']));
        $this->assertSame(['secret s: file /var/run/docker.sock', 'config c: file /etc/shadow'], $removed);
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

    public function test_a_mount_source_is_checked_as_compose_interpolates_it(): void
    {
        // Compose substitutes the default when NOPE is unset, and X from .env.
        $env = ['X' => ['/var/run'], 'DOCS' => ['/etc'], 'DATA_DIR' => ['./storage']];
        foreach ([
            '${NOPE:-/var/run}:/x',
            '${X}:/y',
            '$X/docker.sock:/sock',
            '${SOCK:-/var/run/docker.sock}:/docker.sock',
            '${DOCS}/nginx:/conf:ro',
            '${ROOT:-/}:/hostfs',
            '${A:-${B:-/proc}}:/p',
            '${HOME}/.ssh:/ssh',
            '${BROKEN:-/data:/data',
        ] as $mount) {
            $service = ServiceHardener::withoutEscapes(['volumes' => [$mount, 'data:/data']], $env);

            $this->assertSame(['data:/data'], $service['volumes'], $mount);
        }

        $long = ServiceHardener::withoutEscapes(['volumes' => [
            ['type' => 'bind', 'source' => '${X}', 'target' => '/x'],
            ['type' => 'bind', 'source' => '${DATA_DIR:-./data}', 'target' => '/data'],
        ]], $env);
        $this->assertSame(['${DATA_DIR:-./data}'], array_column($long['volumes'], 'source'));
    }

    public function test_an_interpolated_mount_that_stays_safe_is_kept_as_written(): void
    {
        $kept = [
            '${DATA_DIR:-./data}:/data',
            '${DATA_DIR}/uploads:/uploads',
            '${UNSET_ROOT}/srv:/srv',
            '${VOLUME_NAME:-pgdata}:/var/lib/postgresql/data',
            './a$$b:/b',
        ];
        $service = ServiceHardener::withoutEscapes(['volumes' => $kept], ['DATA_DIR' => ['./storage', '/srv/app']]);

        $this->assertSame($kept, $service['volumes']);
    }

    public function test_a_file_backed_secret_or_config_is_checked_as_compose_interpolates_it(): void
    {
        [$compose, $removed] = ServiceHardener::withoutUnsafeFileSources([
            'secrets' => [
                'sock' => ['file' => '${NOPE:-/var/run/docker.sock}'],
                'shadow' => ['file' => '${X}/shadow'],
                'token' => ['file' => '${TOKEN_FILE:-./secrets/token}'],
                'env' => ['environment' => 'TOKEN'],
            ],
            'configs' => [
                'passwd' => ['file' => '/etc/passwd'],
                'nginx' => ['file' => './nginx.conf'],
            ],
        ], ['X' => ['/etc']]);

        $this->assertSame(['token', 'env'], array_keys($compose['secrets']));
        $this->assertSame(['nginx'], array_keys($compose['configs']));
        $this->assertSame([
            'secret sock: file ${NOPE:-/var/run/docker.sock}',
            'secret shadow: file ${X}/shadow',
            'config passwd: file /etc/passwd',
        ], $removed);
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

            $this->assertArrayNotHasKey('NODE_OPTIONS', $service['environment'], $image);
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

        $this->assertSame(
            ['APP_ENV=production', 'NODE_OPTIONS=--max-old-space-size=268', 'OMP_NUM_THREADS=1', 'MKL_NUM_THREADS=1', 'OPENBLAS_NUM_THREADS=1'],
            $service['environment']
        );
    }

    public function test_thread_pools_are_sized_to_the_cpu_quota_not_the_hosts_cores(): void
    {
        // Kokoro under cpus: 0.75 ran torch with one thread per host core:
        // 57.5 s per request against 2.3 s with the pool matched to the quota.
        $default = ServiceHardener::harden('kokoro', ['image' => 'ghcr.io/remsky/kokoro-fastapi-cpu:v0.9.0']);
        $raised = ServiceHardener::harden('kokoro', ['image' => 'python:3.12', 'cpus' => '2.5']);
        $own = ServiceHardener::harden('kokoro', [
            'image' => 'python:3.12',
            'cpus' => 2,
            'environment' => ['OMP_NUM_THREADS=4'],
        ]);

        $this->assertSame('1', $default['environment']['OMP_NUM_THREADS']);
        $this->assertSame('1', $default['environment']['OPENBLAS_NUM_THREADS']);
        $this->assertSame('3', $raised['environment']['MKL_NUM_THREADS']);
        $this->assertSame(['OMP_NUM_THREADS=4', 'MKL_NUM_THREADS=2', 'OPENBLAS_NUM_THREADS=2'], $own['environment']);
    }

    /**
     * limbas (#97): `image: postgres` resolves to 18, whose entrypoint refuses
     * to start while /var/lib/postgresql/data is a mount point.
     */
    public function test_a_postgres_volume_at_the_legacy_path_is_named_as_pgdata(): void
    {
        $service = ServiceHardener::harden('limbas_pgsql', [
            'image' => 'postgres',
            'volumes' => ['postgres-data:/var/lib/postgresql/data'],
            'environment' => ['POSTGRES_USER' => 'limbasuser'],
        ]);

        $this->assertSame('/var/lib/postgresql/data', $service['environment']['PGDATA']);
        $this->assertSame('limbasuser', $service['environment']['POSTGRES_USER']);
    }

    public function test_the_legacy_postgres_path_is_matched_in_every_form(): void
    {
        $long = ServiceHardener::harden('db', [
            'image' => 'docker.io/postgis/postgis:18-3.5',
            'volumes' => [['type' => 'volume', 'source' => 'pg', 'target' => '/var/lib/postgresql/data/']],
            'environment' => ['POSTGRES_PASSWORD=x'],
        ]);
        $this->assertSame(['POSTGRES_PASSWORD=x', 'PGDATA=/var/lib/postgresql/data'], $long['environment']);

        $bind = ServiceHardener::harden('db', ['image' => 'postgres:18-alpine', 'volumes' => ['./pgdata:/var/lib/postgresql/data:rw']]);
        $this->assertSame(['PGDATA' => '/var/lib/postgresql/data'], $bind['environment']);
    }

    public function test_pgdata_is_left_alone_when_set_or_when_nothing_mounts_the_legacy_path(): void
    {
        $own = ServiceHardener::harden('db', [
            'image' => 'postgres:18',
            'volumes' => ['pg:/var/lib/postgresql/data'],
            'environment' => ['PGDATA' => '/var/lib/postgresql/data/pgdata'],
        ]);
        $this->assertSame(['PGDATA' => '/var/lib/postgresql/data/pgdata'], $own['environment']);

        $modern = ServiceHardener::harden('db', ['image' => 'postgres:18', 'volumes' => ['pg:/var/lib/postgresql']]);
        $this->assertArrayNotHasKey('environment', $modern);

        $other = ServiceHardener::harden('db', ['image' => 'mysql:8', 'volumes' => ['d:/var/lib/postgresql/data']]);
        $this->assertArrayNotHasKey('environment', $other);
    }
}
