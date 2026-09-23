<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\ComposeHarden;
use PHPUnit\Framework\TestCase;

class ComposeHardenTest extends TestCase
{
    public function test_preserves_user_environment_and_adds_only_neutral_limits(): void
    {
        $compose = [
            'services' => [
                'app' => [
                    'image' => 'n8nio/n8n',
                    'environment' => [
                        'HUSKY' => '1',
                        'N8N_HOST' => '0.0.0.0',
                    ],
                    'restart' => 'always',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertSame('always', $result['services']['app']['restart']);
        $this->assertSame('1', $result['services']['app']['environment']['HUSKY']);
        $this->assertSame('0.0.0.0', $result['services']['app']['environment']['N8N_HOST']);
        $this->assertSame('384m', $result['services']['app']['mem_limit']);
        // A heap cap matched to mem_limit is a neutral limit, not a framework
        // opinion: without it Node sizes its heap from the host and is killed.
        $this->assertSame('--max-old-space-size=268', $result['services']['app']['environment']['NODE_OPTIONS']);
        $this->assertArrayNotHasKey('WEB_CONCURRENCY', $result['services']['app']['environment']);
        $this->assertArrayNotHasKey('LEFTHOOK', $result['services']['app']['environment']);
    }

    public function test_appends_to_list_style_environment(): void
    {
        $compose = [
            'services' => [
                'web' => [
                    'image' => 'node:22',
                    'environment' => [
                        'NODE_ENV=development',
                    ],
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);
        $env = $result['services']['web']['environment'];

        $this->assertContains('NODE_ENV=development', $env);
        $this->assertContains('NODE_OPTIONS=--max-old-space-size=268', $env);
        $this->assertSame('unless-stopped', $result['services']['web']['restart']);
    }

    public function test_preserves_framework_commands_and_entrypoints(): void
    {
        $compose = [
            'services' => [
                'app' => [
                    'command' => 'bash -c "rm -f tmp/pids/server.pid && bundle exec rails db:prepare && bundle exec falcon serve --bind http://0.0.0.0:3000"',
                ],
                'worker' => [
                    'command' => ['bundle', 'exec', 'rake', 'db:setup'],
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertSame(
            $compose['services']['app']['command'],
            $result['services']['app']['command']
        );
        $this->assertSame($compose['services']['worker']['command'], $result['services']['worker']['command']);
    }

    public function test_preserves_sidecars_and_caps_memory(): void
    {
        $compose = [
            'services' => [
                'database' => [
                    'image' => 'postgres:16-alpine',
                ],
                'app' => [
                    'build' => '.',
                    'depends_on' => ['database', 'vite'],
                ],
                'vite' => [
                    'build' => '.',
                    'command' => 'pnpm vite dev --host 0.0.0.0 --port 3036',
                ],
                'mailhog' => [
                    'image' => 'mailhog/mailhog',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertArrayHasKey('vite', $result['services']);
        $this->assertArrayHasKey('mailhog', $result['services']);
        $this->assertArrayHasKey('app', $result['services']);
        $this->assertSame(['database', 'vite'], $result['services']['app']['depends_on']);
        $this->assertSame('384m', $result['services']['app']['mem_limit']);
        $this->assertSame('512m', $result['services']['database']['mem_limit']);
        $this->assertSame(1024, $result['services']['app']['pids_limit']);
    }

    public function test_preserves_worker_configuration_and_caps_application_resources(): void
    {
        $compose = [
            'services' => [
                'socket' => [
                    'image' => 'socketcluster/socketcluster:v17.4.0',
                    'environment' => [
                        'SOCKETCLUSTER_WORKERS' => 10,
                        'SOCKETCLUSTER_BROKERS' => 10,
                    ],
                ],
                'application' => [
                    'image' => 'example/api:latest',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertSame(10, $result['services']['socket']['environment']['SOCKETCLUSTER_WORKERS']);
        $this->assertSame(10, $result['services']['socket']['environment']['SOCKETCLUSTER_BROKERS']);
        $this->assertSame('384m', $result['services']['application']['mem_limit']);
        $this->assertSame('512m', $result['services']['socket']['mem_limit']);
    }

    public function test_does_not_inject_application_environment_into_user_compose(): void
    {
        $compose = [
            'services' => [
                'app' => [
                    'image' => 'example/app:latest',
                    'environment' => [
                        'APP_URL' => 'http://localhost',
                    ],
                ],
                'db' => [
                    'image' => 'postgres:16',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertSame('http://localhost', $result['services']['app']['environment']['APP_URL']);
        $this->assertArrayNotHasKey('PUBLIC_URL', $result['services']['app']['environment']);
        $this->assertArrayNotHasKey('HTTPS', $result['services']['app']['environment']);
        $this->assertArrayNotHasKey('APP_URL', $result['services']['db']['environment'] ?? []);
        $this->assertArrayNotHasKey('PUBLIC_URL', $result['services']['db']['environment'] ?? []);
    }

    public function test_url_environment_is_empty_for_invalid_input(): void
    {
        $this->assertSame([], ComposeHarden::urlEnvironment(null));
        $this->assertSame([], ComposeHarden::urlEnvironment('not-a-url'));
    }

    public function test_extract_runtime_sidecars_keeps_datastores_drops_dev_app(): void
    {
        $path = sys_get_temp_dir() . '/compose-sidecars-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($path, <<<'YAML'
services:
  laravel.test:
    build:
      context: ./docker/8.5
      args:
        WWWGROUP: '${WWWGROUP}'
    image: sail-8.5/app
    volumes:
      - '.:/var/www/html'
  mysql:
    image: 'mysql:8.4'
    environment:
      MYSQL_DATABASE: '${DB_DATABASE}'
      MYSQL_USER: '${DB_USERNAME}'
      MYSQL_PASSWORD: '${DB_PASSWORD}'
    ports:
      - '3306:3306'
    volumes:
      - 'sail-mysql:/var/lib/mysql'
  redis:
    image: 'redis:alpine'
  typesense:
    image: 'typesense/typesense:27.1'
    environment:
      TYPESENSE_API_KEY: '${TYPESENSE_API_KEY:-xyz}'
  mailpit:
    image: 'axllent/mailpit:latest'
volumes:
  sail-mysql:
    driver: local
YAML
        );
        try {
            $result = ComposeHarden::extractRuntimeSidecars($path);

            $this->assertArrayHasKey('mysql', $result['services']);
            $this->assertArrayHasKey('redis', $result['services']);
            $this->assertArrayHasKey('typesense', $result['services']);
            $this->assertArrayNotHasKey('laravel.test', $result['services']);
            $this->assertArrayNotHasKey('mailpit', $result['services']);
            $this->assertArrayNotHasKey('ports', $result['services']['mysql']);
            $this->assertSame('mysql:8.4', $result['services']['mysql']['image']);
            $this->assertSame('app', $result['services']['mysql']['environment']['MYSQL_DATABASE']);
            $this->assertSame('512m', $result['services']['mysql']['mem_limit']);
            $this->assertSame('%', $result['services']['mysql']['environment']['MYSQL_ROOT_HOST']);
            // The catalogue's healthcheck reaches the service. Its exact
            // shape is MysqlHealthcheckTest's subject -- asserting the literal
            // here made a deliberate fix to it read as a regression.
            $this->assertSame('CMD-SHELL', $result['services']['mysql']['healthcheck']['test'][0]);
            $this->assertStringContainsString(
                'ping',
                $result['services']['mysql']['healthcheck']['test'][1]
            );
            $this->assertSame('mysql', $result['env']['DB_CONNECTION']);
            $this->assertSame('mysql', $result['env']['DB_HOST']);
            $this->assertSame('app', $result['env']['DB_DATABASE']);
            $this->assertSame('redis', $result['env']['REDIS_HOST']);
            $this->assertSame('redis://redis:6379/0', $result['env']['REDIS_URL']);
            $this->assertSame('typesense', $result['env']['TYPESENSE_HOST']);
            $this->assertSame('true', $result['env']['TYPESENSE_ENABLED']);
            $this->assertSame('xyz', $result['env']['TYPESENSE_API_KEY']);
            $this->assertArrayHasKey('sail-mysql', $result['volumes']);
        } finally {
            unlink($path);
        }
    }

    public function test_node_heap_scales_with_mem_limit_and_preserves_existing_options(): void
    {
        $compose = [
            'services' => [
                'calcom' => [
                    'image' => 'calcom/cal.com:latest',
                    'mem_limit' => '1g',
                ],
                'custom' => [
                    'image' => 'node:22',
                    'environment' => [
                        'NODE_OPTIONS' => '--max-old-space-size=96 --enable-source-maps',
                    ],
                ],
                'db' => [
                    'image' => 'postgres:16',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertSame('1g', $result['services']['calcom']['mem_limit']);
        $this->assertSame('--max-old-space-size=716', $result['services']['calcom']['environment']['NODE_OPTIONS']);
        $this->assertSame(
            '--max-old-space-size=96 --enable-source-maps',
            $result['services']['custom']['environment']['NODE_OPTIONS']
        );
        $this->assertArrayNotHasKey('NODE_OPTIONS', $result['services']['db']['environment'] ?? []);
        $this->assertSame('512m', $result['services']['db']['mem_limit']);
    }

    public function test_unnamed_app_service_gets_half_gig_and_matching_node_heap(): void
    {
        $compose = [
            'services' => [
                'calcom' => [
                    'image' => 'calcom/cal.com:latest',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        $this->assertSame('512m', $result['services']['calcom']['mem_limit']);
        $this->assertSame('--max-old-space-size=358', $result['services']['calcom']['environment']['NODE_OPTIONS']);
    }

    public function test_locally_built_service_still_gets_a_node_heap_cap(): void
    {
        $compose = [
            'services' => [
                'web' => [
                    'build' => ['context' => '.'],
                    'mem_limit' => '1g',
                ],
                'cache' => [
                    'image' => 'redis:7-alpine',
                ],
                'proxy' => [
                    'image' => 'nginx:alpine',
                ],
            ],
        ];

        $result = ComposeHarden::apply($compose);

        // No image to match on: the runtime is unknown, so cap rather than
        // let a Next/Nuxt build be OOM-killed at its cgroup limit.
        $this->assertSame('--max-old-space-size=716', $result['services']['web']['environment']['NODE_OPTIONS']);
        $this->assertArrayNotHasKey('NODE_OPTIONS', $result['services']['cache']['environment'] ?? []);
        $this->assertArrayNotHasKey('NODE_OPTIONS', $result['services']['proxy']['environment'] ?? []);
    }

    public function test_removes_container_escape_options_and_docker_socket_mounts(): void
    {
        $compose = ['services' => ['app' => [
            'image' => 'example/app',
            'privileged' => true,
            'pid' => 'host',
            'network_mode' => 'host',
            'devices' => ['/dev/kvm:/dev/kvm'],
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock',
                './data:/app/data',
            ],
        ]]];

        $service = ComposeHarden::apply($compose)['services']['app'];

        $this->assertArrayNotHasKey('privileged', $service);
        $this->assertArrayNotHasKey('pid', $service);
        $this->assertArrayNotHasKey('network_mode', $service);
        $this->assertArrayNotHasKey('devices', $service);
        $this->assertSame(['./data:/app/data'], $service['volumes']);
    }

    public function test_removes_capability_and_confinement_options(): void
    {
        // Dropping `privileged` is not enough on its own: every capability it
        // implies can be asked for one at a time, and the confinement that
        // would otherwise catch the abuse can be switched off by name.
        $compose = ['services' => ['app' => [
            'image' => 'example/app',
            'cap_add' => ['SYS_ADMIN', 'SYS_PTRACE', 'ALL'],
            'security_opt' => ['apparmor:unconfined', 'seccomp:unconfined'],
            'userns_mode' => 'host',
            'cgroup_parent' => '/',
            'group_add' => ['docker'],
            'sysctls' => ['kernel.shm_rmid_forced' => 0],
            'device_cgroup_rules' => ['c *:* rwm'],
        ]]];

        $service = ComposeHarden::apply($compose)['services']['app'];

        foreach (
            ['cap_add', 'security_opt', 'userns_mode', 'cgroup_parent', 'group_add', 'sysctls', 'device_cgroup_rules']
            as $key
        ) {
            $this->assertArrayNotHasKey($key, $service, "{$key} survived hardening");
        }
    }

    public function test_removes_docker_socket_mounted_through_its_parent_directory(): void
    {
        // Matching the socket path alone left the directory it lives in as a
        // way to hand over the daemon without naming the socket.
        $compose = ['services' => ['app' => [
            'image' => 'example/app',
            'volumes' => [
                '/var/run:/var/run',
                '/run:/hostrun',
                '/run/docker.sock:/tmp/d.sock',
                './data:/app/data',
            ],
        ]]];

        $service = ComposeHarden::apply($compose)['services']['app'];

        $this->assertSame(['./data:/app/data'], $service['volumes']);
    }

    public function test_removes_binds_of_the_host_filesystem(): void
    {
        $compose = ['services' => ['app' => [
            'image' => 'example/app',
            'volumes' => [
                '/:/hostfs',
                '/etc:/hostetc:ro',
                '/var/lib/docker:/var/lib/docker',
                '/proc:/hostproc',
                'appdata:/var/lib/app',
                './src:/app/src',
            ],
        ]]];

        $service = ComposeHarden::apply($compose)['services']['app'];

        // A named volume and a path inside the project are the legitimate cases
        // and must survive.
        $this->assertSame(['appdata:/var/lib/app', './src:/app/src'], $service['volumes']);
    }

    public function test_long_form_mounts_are_filtered_too(): void
    {
        $compose = ['services' => ['app' => [
            'image' => 'example/app',
            'volumes' => [
                ['type' => 'bind', 'source' => '/', 'target' => '/hostfs'],
                ['type' => 'bind', 'source' => '/var/run/docker.sock', 'target' => '/sock'],
                ['type' => 'volume', 'source' => 'appdata', 'target' => '/data'],
            ],
        ]]];

        $service = ComposeHarden::apply($compose)['services']['app'];

        $this->assertSame(
            [['type' => 'volume', 'source' => 'appdata', 'target' => '/data']],
            $service['volumes']
        );
    }

    public function test_a_path_that_only_starts_like_run_is_kept(): void
    {
        // `/var/running` is not `/var/run`, and the socket pattern must not
        // widen into a prefix match.
        $compose = ['services' => ['app' => [
            'image' => 'example/app',
            'volumes' => ['/var/running:/app/running', './run:/app/run'],
        ]]];

        $service = ComposeHarden::apply($compose)['services']['app'];

        $this->assertSame(['/var/running:/app/running', './run:/app/run'], $service['volumes']);
    }

    public function test_a_build_base_service_is_not_restarted(): void
    {
        // Chatwoot: `base` only builds the image rails and sidekiq run, exits
        // 0, and under unless-stopped restarted forever.
        $compose = ['services' => [
            'base' => ['build' => ['context' => '.'], 'image' => 'chatwoot:latest'],
            'rails' => ['image' => 'chatwoot:latest', 'ports' => ['3000:3000'], 'depends_on' => ['postgres']],
            'sidekiq' => ['image' => 'chatwoot', 'command' => ['bundle', 'exec', 'sidekiq']],
            'postgres' => ['image' => 'postgres:16'],
        ]];

        $services = ComposeHarden::apply($compose)['services'];

        $this->assertSame(['base'], ComposeHarden::oneShotServices($compose));
        $this->assertSame('no', $services['base']['restart']);
        $this->assertSame('unless-stopped', $services['rails']['restart']);
        $this->assertSame('unless-stopped', $services['sidekiq']['restart']);
        $this->assertSame('unless-stopped', $services['postgres']['restart']);
        // Quoted, or YAML 1.1 readers take it for a boolean.
        $this->assertStringContainsString("restart: 'no'", \Symfony\Component\Yaml\Yaml::dump($services['base']));
    }

    public function test_an_anchor_only_base_service_is_not_restarted(): void
    {
        // Chatwoot's production compose: `base` carries the anchor `rails` and
        // `sidekiq` inherit from. It names an image and nothing else — no
        // build, no command, no entrypoint, no port — so it runs the image's
        // default command (`irb`) and exits 0. Under unless-stopped that is a
        // container restarting every few seconds forever.
        $compose = ['services' => [
            'base' => ['image' => 'chatwoot/chatwoot:latest', 'env_file' => '.env', 'volumes' => ['storage_data:/app/storage']],
            'rails' => [
                'image' => 'chatwoot/chatwoot:latest',
                'ports' => ['3000:3000'],
                'entrypoint' => 'docker/entrypoints/rails.sh',
                'command' => ['bundle', 'exec', 'rails', 's', '-p', '3000', '-b', '0.0.0.0'],
                'depends_on' => ['postgres', 'redis'],
            ],
            'sidekiq' => [
                'image' => 'chatwoot/chatwoot:latest',
                'command' => ['bundle', 'exec', 'sidekiq', '-C', 'config/sidekiq.yml'],
                'depends_on' => ['postgres', 'redis'],
            ],
            'postgres' => ['image' => 'pgvector/pgvector:pg16'],
            'redis' => ['image' => 'redis:alpine'],
        ]];

        $services = ComposeHarden::apply($compose)['services'];

        $this->assertSame(['base'], ComposeHarden::oneShotServices($compose));
        $this->assertSame('no', $services['base']['restart']);
        $this->assertSame('unless-stopped', $services['rails']['restart']);
        $this->assertSame('unless-stopped', $services['sidekiq']['restart']);
        $this->assertStringContainsString("restart: 'no'", \Symfony\Component\Yaml\Yaml::dump($services['base']));
    }

    public function test_a_service_waited_on_to_complete_is_not_restarted(): void
    {
        $compose = ['services' => [
            'migrate' => ['image' => 'acme/app', 'command' => 'php artisan migrate --force'],
            'app' => [
                'image' => 'acme/app',
                'ports' => ['8080:80'],
                'depends_on' => ['migrate' => ['condition' => 'service_completed_successfully']],
            ],
        ]];

        $services = ComposeHarden::apply($compose)['services'];

        $this->assertSame('no', $services['migrate']['restart']);
        $this->assertSame('unless-stopped', $services['app']['restart']);
    }

    public function test_a_single_web_service_keeps_its_restart_policy(): void
    {
        $compose = ['services' => ['web' => ['build' => '.', 'image' => 'acme/web']]];

        $this->assertSame([], ComposeHarden::oneShotServices($compose));
        $this->assertSame('unless-stopped', ComposeHarden::apply($compose)['services']['web']['restart']);
    }

    public function test_an_authors_restart_policy_is_kept_even_on_a_one_shot(): void
    {
        $compose = ['services' => [
            'base' => ['build' => '.', 'image' => 'acme/app', 'restart' => 'on-failure'],
            'worker' => ['image' => 'acme/app', 'restart' => 'always'],
            'init' => ['image' => 'busybox', 'restart' => 'no'],
            'app' => [
                'image' => 'acme/app',
                'depends_on' => ['init' => ['condition' => 'service_completed_successfully']],
            ],
        ]];

        $services = ComposeHarden::apply($compose)['services'];

        $this->assertSame([], ComposeHarden::oneShotServices($compose));
        $this->assertSame('on-failure', $services['base']['restart']);
        $this->assertSame('always', $services['worker']['restart']);
        $this->assertSame('no', $services['init']['restart']);
    }

    public function test_an_ambiguous_builder_is_left_alone(): void
    {
        // Each rule keeps a long-running service out: something waits on it
        // running, it publishes a port, or every user of the image builds it.
        $compose = ['services' => [
            'api' => ['build' => '.', 'image' => 'acme/api'],
            'proxy' => ['image' => 'nginx', 'depends_on' => ['api']],
            'consumer' => ['image' => 'acme/api', 'command' => 'run-consumer'],
            'site' => ['build' => '.', 'image' => 'acme/site', 'expose' => ['3000']],
            'site-worker' => ['image' => 'acme/site'],
            'one' => ['build' => './one', 'image' => 'acme/shared'],
            'two' => ['build' => './two', 'image' => 'acme/shared'],
            'job' => ['image' => 'acme/job'],
            'a' => ['image' => 'acme/a', 'depends_on' => ['job' => ['condition' => 'service_completed_successfully']]],
            'b' => ['image' => 'acme/b', 'depends_on' => ['job' => ['condition' => 'service_healthy']]],
        ]];

        $this->assertSame([], ComposeHarden::oneShotServices($compose));
    }

    /**
     * The one-shot rules read the file the repository wrote, not a prepared
     * copy of it.
     *
     * `RuntimeSidecars::keep()` strips `ports` from every service it keeps, and
     * a published port is what vetoes those rules. Classified after the strip,
     * a long-running server with a sibling on the same image looks exactly like
     * an anchor the sibling inherits from, and was given `restart: "no"` — so
     * it never came back after an exit or a host reboot.
     */
    public function test_a_published_port_vetoes_the_one_shot_rules_after_a_caller_stripped_it(): void
    {
        $asWritten = [
            'server' => ['image' => 'ghcr.io/acme/thing:latest', 'ports' => ['3000:3000']],
            'worker' => ['image' => 'ghcr.io/acme/thing:latest', 'command' => ['node', 'worker.js']],
        ];
        $prepared = $asWritten;
        unset($prepared['server']['ports']);

        $result = ComposeHarden::apply(['services' => $prepared], null, $asWritten);

        $this->assertNotSame('no', $result['services']['server']['restart'] ?? null);
    }

    /** A real anchor — no port, no command of its own — still stops restarting. */
    public function test_an_anchor_its_siblings_inherit_is_still_one_shot(): void
    {
        $services = [
            'base' => ['image' => 'chatwoot/chatwoot:latest'],
            'rails' => ['image' => 'chatwoot/chatwoot:latest', 'command' => 'rails s'],
        ];

        $result = ComposeHarden::apply(['services' => $services], null, $services);

        $this->assertSame('no', $result['services']['base']['restart']);
        $this->assertNotSame('no', $result['services']['rails']['restart'] ?? null);
    }

    /**
     * An expose-only app service gets its detected primary port published, so
     * the account container binds the port the DinD proxy targets (engine#234).
     */
    public function test_publishes_the_detected_primary_port_for_an_expose_only_service(): void
    {
        $compose = ['services' => [
            'app' => ['build' => '.', 'expose' => ['8080']],
            'db' => ['image' => 'postgres:16'],
        ]];

        $result = ComposeHarden::withPublishedPrimaryPort($compose);

        $this->assertSame(8080, $result['published']);
        $this->assertSame(['8080:8080'], $result['compose']['services']['app']['ports']);
        $this->assertArrayNotHasKey('ports', $result['compose']['services']['db']);
    }

    /** A service that already publishes the primary port is left untouched. */
    public function test_leaves_a_service_that_already_publishes_the_port_unchanged(): void
    {
        $compose = ['services' => [
            'app' => ['build' => '.', 'ports' => ['8080:80'], 'expose' => ['8080']],
        ]];

        $result = ComposeHarden::withPublishedPrimaryPort($compose);

        $this->assertNull($result['published']);
        $this->assertSame(['8080:80'], $result['compose']['services']['app']['ports']);
    }

    /**
     * Traefik's routed port survives as `expose:` after HostIngress strips the
     * proxy, then gets published — the recipes' explicit `<primary>:<primary>`
     * is what this now makes unnecessary.
     */
    public function test_publishes_a_routed_port_left_as_expose_by_ingress_stripping(): void
    {
        $compose = ['services' => [
            'app' => [
                'build' => '.',
                'labels' => ['traefik.http.services.app.loadbalancer.server.port' => '5000'],
            ],
            'traefik' => ['image' => 'traefik:v3.1'],
        ]];

        $stripped = ComposeHarden::withoutHostIngress($compose)['compose'];
        $result = ComposeHarden::withPublishedPrimaryPort($stripped);

        $this->assertSame(5000, $result['published']);
        $this->assertContains('5000:5000', $result['compose']['services']['app']['ports']);
    }
}
