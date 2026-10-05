<?php

namespace Tests\Unit\Deploy\Compose;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\RuntimeSidecars as DindRuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\RuntimeSidecars;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a project's own compose file contributes to a deploy.
 *
 * What is dropped matters as much as what is kept: the workstation
 * application service, the mail catcher, the published host ports, the
 * networks that do not exist inside an account. And what survives has its
 * credentials pinned, because a datastore that regenerates its password on
 * every deploy locks the application out of data it wrote yesterday.
 */
class RuntimeSidecarsTest extends TestCase
{
    private function dindForMerger(): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('username')->willReturn('acme');
        $dind->method('system')->willReturn($this->createStub(System::class));
        $dind->method('shell')->willReturn(new ShellOperations($dind));

        return $dind;
    }

    private const STACK = <<<'YAML'
    services:
      app:
        build: .
        ports:
          - "8080:8080"
        depends_on:
          - db
          - mailpit
        environment:
          DATABASE_URL: postgres://shop:secret@db:5432/shop
      db:
        image: postgres:16
        ports:
          - "5432:5432"
        environment:
          POSTGRES_USER: shop
          POSTGRES_PASSWORD: secret
          POSTGRES_DB: shop
        volumes:
          - dbdata:/var/lib/postgresql/data
      mailpit:
        image: axllent/mailpit
        ports:
          - "8025:8025"
    volumes:
      dbdata:
    YAML;

    /**
     * @return array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}
     */
    private function extract(string $yaml, bool $backingOnly = true): array
    {
        return RuntimeSidecars::fromYaml($yaml, $backingOnly, null, 'github.com/acme/shop');
    }

    /**
     * dpaste's shape: the application keeps its sqlite database on a named
     * volume its file declares, and says so in its own environment.
     *
     * The deploy replaces that app service with a build of the same
     * repository, so everything the service said about where the application
     * keeps its state — and what environment it runs with — has to survive
     * being dropped. Otherwise the replacement starts with no database at all
     * (`sqlite3.OperationalError: unable to open database file`) and
     * restart-loops.
     */
    private const APP_STATE = <<<'YAML'
    services:
      app:
        build: .
        image: app
        command: ./manage.py runserver 0:8000
        environment:
          DATABASE_URL: sqlite:////db/app.sqlite
          STATIC_ROOT: /collectstatic
        volumes:
          - .:/app:delegated
          - data_db:/db
          - data_static:/collectstatic
      migration:
        image: app
        command: ./manage.py migrate --noinput
        volumes:
          - .:/app:delegated
          - data_db:/db
    volumes:
      data_db:
      data_static:
    YAML;

    public function test_the_apps_own_mounts_and_environment_survive_it_being_replaced(): void
    {
        $result = RuntimeSidecars::fromYaml(self::APP_STATE, false, null, 'github.com/acme/shop');

        // Only the named volumes the file declares; a bind mount would put the
        // account's checkout inside the running container.
        $this->assertSame(
            ['data_db:/db', 'data_static:/collectstatic'],
            $result['app_mounts']
        );
        $this->assertSame(
            ['DATABASE_URL' => 'sqlite:////db/app.sqlite', 'STATIC_ROOT' => '/collectstatic'],
            $result['app_env']
        );
    }

    /**
     * The job shares the app's image, so it must not be read as the
     * application — its mounts and environment are the job's own, and the
     * job's env here would clobber the app's DATABASE_URL.
     */
    public function test_the_jobs_own_mounts_are_not_taken_for_the_apps(): void
    {
        $result = RuntimeSidecars::fromYaml(self::APP_STATE, false, null, 'github.com/acme/shop');

        $this->assertArrayHasKey('migration', $result['services']);
        $this->assertNotContains('.:/app:delegated', $result['app_mounts']);
    }

    /**
     * A template's app is a published image nobody builds here, so neither the
     * tag nor the mounts may be adopted as this deploy's own.
     */
    public function test_a_template_contributes_no_build_image(): void
    {
        $template = "services:\n  app:\n    image: ghcr.io/acme/shop:stable\n    ports:\n      - '8080:8080'\n";
        $result = RuntimeSidecars::fromYaml($template, true, null, 'github.com/acme/shop');

        $this->assertNull($result['build_image']);
    }

    /** Playerr's docker-compose.casaos.yml is the app itself on `playerr:latest`. */
    public function test_a_template_service_named_after_the_repository_is_not_a_backing_service(): void
    {
        $casaos = "services:\n  playerr:\n    image: playerr:latest\n    ports:\n      - '2727:2727'\n";
        $this->assertSame([], RuntimeSidecars::fromYaml($casaos, true, null, 'github.com/Maikboarder/Playerr')['services']);

        $byImage = "services:\n  web:\n    image: maikboarder/playerr:latest\n  db:\n    image: postgres:16\n";
        $this->assertSame(['db'], array_keys(RuntimeSidecars::fromYaml($byImage, true, null, 'github.com/maikboarder/playerr')['services']));

        // A datastore keeps its place even in a repository of the same name.
        $redis = "services:\n  redis:\n    image: redis:7\n";
        $this->assertSame(['redis'], array_keys(RuntimeSidecars::fromYaml($redis, true, null, 'github.com/acme/redis')['services']));
    }

    /** Without a repository identity, the name the repository's compose gives its root build. */
    public function test_a_template_service_named_like_the_root_build_is_not_a_backing_service(): void
    {
        $casaos = "services:\n  playerr:\n    image: playerr:latest\n    ports:\n      - '2727:2727'\n";
        $this->assertSame(['playerr'], array_keys(RuntimeSidecars::fromYaml($casaos, true)['services']));
        $this->assertSame([], RuntimeSidecars::fromYaml($casaos, true, null, null, null, null, null, [], null, null, ['playerr'])['services']);

        // Matched on the image name or container_name as well; a datastore stays.
        $byImage = "services:\n  web:\n    image: playerr:2\n  db:\n    image: postgres:16\n";
        $this->assertSame(['db'], array_keys(RuntimeSidecars::fromYaml($byImage, true, null, null, null, null, null, [], null, null, ['playerr'])['services']));
        $byContainer = "services:\n  web:\n    image: ghcr.io/x/y:1\n    container_name: Playerr\n";
        $this->assertSame([], RuntimeSidecars::fromYaml($byContainer, true, null, null, null, null, null, [], null, null, ['playerr'])['services']);
        $redis = "services:\n  redis:\n    image: redis:7\n";
        $this->assertSame(['redis'], array_keys(RuntimeSidecars::fromYaml($redis, true, null, null, null, null, null, [], null, null, ['redis'])['services']));
    }

    public function test_root_build_service_names_are_the_services_built_from_the_repository_root(): void
    {
        $yaml = "services:\n  playerr:\n    build: .\n    container_name: Playerr-App\n"
            . "  web:\n    build:\n      dockerfile: dev.Dockerfile\n"
            . "  docs:\n    build: ./docs\n  db:\n    image: postgres:16\n";

        $this->assertSame(['playerr', 'playerr-app', 'web'], RuntimeSidecars::rootBuildServiceNames($yaml));
        $this->assertSame([], RuntimeSidecars::rootBuildServiceNames('not: [yaml'));
    }

    /**
     * rapidbay's example file, deployed from an archive: no repository name to
     * match, but the app `links:` its indexer, so it is the top of the stack.
     */
    public function test_a_template_app_that_links_its_dependency_is_not_a_backing_service(): void
    {
        $template = "services:\n  jackett:\n    image: linuxserver/jackett\n    ports:\n      - 9117:9117\n"
            . "  rapidbay:\n    image: hauxir/rapidbay:latest\n    environment:\n"
            . "      - JACKETT_HOST=http://jackett:9117\n    ports:\n      - 5000:5000\n    links:\n      - jackett\n";
        $result = RuntimeSidecars::fromYaml($template, true);

        $this->assertSame(['jackett'], array_keys($result['services']));
    }

    public function test_only_the_backing_services_are_kept(): void
    {
        // The app service is the thing being deployed, not a dependency of it;
        // mailpit would silently swallow every outgoing email.
        $result = $this->extract(self::STACK);

        $this->assertSame(['db'], array_keys($result['services']));
    }

    public function test_published_ports_are_stripped(): void
    {
        // A host port inside an account collides with every other account's
        // stack, and the database must not be reachable from outside anyway.
        $result = $this->extract(self::STACK);

        $this->assertArrayNotHasKey('ports', $result['services']['db']);
    }

    public function test_networks_that_mean_nothing_here_are_stripped(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          db:
            image: postgres:16
            networks: [backend]
            extra_hosts: ["host.docker.internal:host-gateway"]
        networks:
          backend:
        YAML);

        foreach (['networks', 'extra_hosts'] as $key) {
            $this->assertArrayNotHasKey($key, $result['services']['db'], $key);
        }
    }

    public function test_a_service_behind_a_profile_is_not_adopted(): void
    {
        // `docker compose up` with no --profile never starts it, so it is not
        // part of the stack its author deploys. Stripping the key instead of
        // the service promoted opt-in extras to always-on: OpenCart's compose
        // gates postgres, redis, memcached and adminer that way, and the
        // account got all four.
        $result = $this->extract(<<<'YAML'
        services:
          db:
            image: postgres:16
          adminer:
            image: adminer:latest
            profiles: [adminer]
        YAML);

        $this->assertSame(['db'], array_keys($result['services']));
    }

    public function test_a_reference_to_a_dropped_service_is_removed(): void
    {
        // Compose refuses to start a stack that depends on a service the file
        // no longer defines.
        $result = $this->extract(<<<'YAML'
        services:
          db:
            image: postgres:16
            depends_on: [mailpit]
          mailpit:
            image: axllent/mailpit
        YAML);

        $this->assertArrayNotHasKey('depends_on', $result['services']['db']);
    }

    public function test_the_workstation_app_service_is_never_depended_on(): void
    {
        // `laravel.test` is Sail's own app container: a build with the
        // developer's uid passed in, which cannot be built in an account. It
        // is dropped, and the name is stripped from every depends_on
        // unconditionally - a sibling still pointing at it is a stack compose
        // refuses to start.
        $result = $this->extract(<<<'YAML'
        services:
          laravel.test:
            build:
              context: ./vendor/laravel/sail/runtimes/8.3
              args:
                WWWGROUP: '${WWWGROUP}'
          mysql:
            image: mysql:8
            depends_on: ["laravel.test"]
        YAML);

        $this->assertSame(['mysql'], array_keys($result['services']));
        $this->assertArrayNotHasKey('depends_on', $result['services']['mysql']);
    }

    public function test_a_service_that_only_builds_is_dropped(): void
    {
        // Nothing in an account can build a service the engine did not
        // generate a Dockerfile for.
        $result = $this->extract(<<<'YAML'
        services:
          worker:
            build: ./worker
          db:
            image: postgres:16
        YAML);

        $this->assertSame(['db'], array_keys($result['services']));
    }

    public function test_a_volume_the_kept_services_mount_survives(): void
    {
        $result = $this->extract(self::STACK);

        $this->assertArrayHasKey('dbdata', $result['volumes']);
    }

    public function test_the_kept_services_are_hardened(): void
    {
        $result = $this->extract(self::STACK);

        // Its `ports` are stripped and the app that needed it was dropped, so no
        // policy is added here: GeneratedCompose gives every sidecar one.
        $this->assertArrayNotHasKey('restart', $result['services']['db']);
        $this->assertArrayHasKey('mem_limit', $result['services']['db']);
        $this->assertArrayHasKey('pids_limit', $result['services']['db']);
    }

    public function test_the_application_is_told_how_to_reach_the_database(): void
    {
        $result = $this->extract(self::STACK);

        $this->assertNotSame([], $result['env']);
        $this->assertStringContainsString('db', implode(' ', $result['env']));
    }

    public function test_the_credentials_the_project_chose_are_pinned(): void
    {
        // The point of pinning: a password regenerated on the next deploy
        // locks the app out of the data it wrote yesterday.
        $result = $this->extract(self::STACK);
        $environment = $result['services']['db']['environment'];

        $this->assertSame('shop', $environment['POSTGRES_USER']);
        $this->assertSame('secret', $environment['POSTGRES_PASSWORD']);
        $this->assertSame('shop', $environment['POSTGRES_DB']);
    }

    public function test_a_database_with_no_credentials_is_given_some(): void
    {
        // Postgres refuses to start without a password, so leaving the blank
        // in place is a stack that never comes up.
        $result = $this->extract("services:\n  db:\n    image: postgres:16\n");
        $environment = $result['services']['db']['environment'];

        $this->assertNotEmpty($environment['POSTGRES_PASSWORD']);
    }

    public function test_a_harvested_database_with_no_credentials_gets_the_accounts_own(): void
    {
        // Phorge's recipe ships exactly this: a mysql service with no
        // environment at all. It used to come up as app/app with root@'%'
        // on `app` too (engine#189).
        $passwords = SidecarPasswords::derived('account-seed');
        $result = RuntimeSidecars::fromYaml(
            "services:\n  db:\n    image: mysql:8.0\n    volumes:\n      - db:/var/lib/mysql\nvolumes:\n  db: {}\n",
            true,
            null,
            'github.com/acme/shop',
            null,
            null,
            $passwords
        );
        $db = $result['services']['db']['environment'];

        $this->assertSame($passwords->for('MYSQL_PASSWORD'), $db['MYSQL_PASSWORD']);
        $this->assertSame($passwords->for('MYSQL_ROOT_PASSWORD'), $db['MYSQL_ROOT_PASSWORD']);
        $this->assertSame($db['MYSQL_PASSWORD'], $result['env']['DB_PASSWORD']);
        $this->assertStringContainsString(':' . $db['MYSQL_PASSWORD'] . '@db:3306', $result['env']['DATABASE_URL']);
    }

    public function test_a_dropped_app_services_self_hosting_flag_is_harvested(): void
    {
        // Only the flags that change what the application *is*. An app that
        // was configured as self-hosted and loses the flag on deploy tries to
        // reach a SaaS control plane it has no account on.
        $result = $this->extract(<<<'YAML'
        services:
          app:
            image: ghcr.io/acme/shop:latest
            environment:
              SELF_HOSTED: "true"
              STRIPE_SECRET: sk_test_123
          db:
            image: postgres:16
        YAML);

        $this->assertSame('true', $result['env']['SELF_HOSTED']);
    }

    public function test_a_dropped_app_services_own_secrets_are_not_harvested(): void
    {
        // The engine generates the deployed application's configuration; a
        // key copied out of the workstation stack would be the developer's,
        // shared across every account that deploys the same repo.
        $result = $this->extract(<<<'YAML'
        services:
          app:
            image: ghcr.io/acme/shop:latest
            environment:
              APP_KEY: base64:abcdef
              STRIPE_SECRET: sk_test_123
          db:
            image: postgres:16
        YAML);

        $this->assertArrayNotHasKey('APP_KEY', $result['env']);
        $this->assertArrayNotHasKey('STRIPE_SECRET', $result['env']);
    }

    public function test_a_build_only_service_is_dropped_even_when_the_whole_stack_is_wanted(): void
    {
        // backingServicesOnly false asks for the app service too, but this
        // one has no image and nothing in an account can build it.
        $result = $this->extract(self::STACK, false);

        $this->assertSame(['db'], array_keys($result['services']));
    }

    public function test_an_image_backed_app_service_is_kept_when_nothing_is_replacing_it(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          app:
            image: ghcr.io/acme/shop:latest
            ports:
              - "8080:8080"
          db:
            image: postgres:16
        YAML, false);

        $this->assertSame(['app', 'db'], array_keys($result['services']));
        $this->assertArrayNotHasKey('ports', $result['services']['app']);
    }

    /**
     * BookStack's workstation file: its node asset watcher runs the checkout
     * from a bind on a stock image, cannot write the host-built node_modules
     * in an account and restart-loops, so every deploy ended partial.
     */
    public function test_an_image_service_running_the_checkout_from_a_bind_is_dropped(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          app:
            build: .
            volumes:
              - ./:/app
            depends_on:
              node:
                condition: service_started
          db:
            image: mysql:8.4
            volumes:
              - ./:/backup:ro
          node:
            image: node:22-alpine
            working_dir: /app
            user: node
            volumes:
              - ./:/app
            entrypoint: /app/dev/docker/entrypoint.node.sh
          worker:
            image: acme/worker
            volumes:
              - ./storage:/data
        YAML, false);

        $this->assertSame(['db', 'worker'], array_keys($result['services']));
    }

    /**
     * Sail's mysql binds a script from require-dev laravel/sail, which a
     * `--no-dev` install never has: Docker made a directory there and the
     * entrypoint failed sourcing it (Bagisto).
     */
    public function test_a_kept_sidecar_loses_binds_into_installed_dependencies(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          laravel.test:
            build:
              context: ./vendor/laravel/sail/runtimes/8.3
              args:
                WWWGROUP: '${WWWGROUP}'
            volumes:
              - '.:/var/www/html'
          mysql:
            image: 'mysql/mysql-server:8.0'
            volumes:
              - 'sail-mysql:/var/lib/mysql'
              - './vendor/laravel/sail/database/mysql/create-testing-database.sh:/docker-entrypoint-initdb.d/10-create-testing-database.sh'
              - {type: bind, source: ./node_modules/x, target: /x}
              - 'vendor:/opt/vendor'
              - './docker/my.cnf:/etc/mysql/conf.d/my.cnf'
        volumes:
          sail-mysql:
          vendor:
        YAML, false);

        $this->assertSame(
            ['sail-mysql:/var/lib/mysql', 'vendor:/opt/vendor', './docker/my.cnf:/etc/mysql/conf.d/my.cnf'],
            $result['services']['mysql']['volumes']
        );
        $this->assertSame([
            'mysql: ./vendor/laravel/sail/database/mysql/create-testing-database.sh:/docker-entrypoint-initdb.d/10-create-testing-database.sh',
            'mysql: ./node_modules/x',
        ], $result['dropped_mounts']);
    }

    /**
     * Shlink's dev compose: its databases run as the laptop's uid with their
     * data directory in the checkout, and MySQL refused to initialise there.
     */
    public function test_a_kept_datastore_runs_as_its_own_user_on_a_volume(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          shlink_php:
            user: 1000:1000
            build:
              context: .
            volumes:
              - ./:/home/shlink/www
          shlink_db_mysql:
            user: 1000:1000
            image: mysql:8.0
            volumes:
              - ./:/home/shlink/www
              - ./data/infra/database:/var/lib/mysql
              - ./data/infra/my.cnf:/etc/mysql/conf.d/my.cnf
          shlink_redis_acl:
            image: redis:7.4-alpine
            volumes:
              - ./data/infra/redis/redis-acl.conf:/usr/local/etc/redis/redis.conf
          shlink_swagger_ui:
            image: swaggerapi/swagger-ui:v5.11.3
            volumes:
              - ./docs/swagger:/app
        YAML, false);

        $mysql = $result['services']['shlink_db_mysql'];
        $this->assertArrayNotHasKey('user', $mysql);
        $this->assertSame(
            ['./:/home/shlink/www', 'shlink_db_mysql-data:/var/lib/mysql', './data/infra/my.cnf:/etc/mysql/conf.d/my.cnf'],
            $mysql['volumes']
        );
        $this->assertArrayHasKey('shlink_db_mysql-data', $result['volumes']);
        $this->assertSame(['shlink_db_mysql: ./data/infra/database:/var/lib/mysql'], $result['replaced_mounts']);
        $this->assertSame(
            ['./data/infra/redis/redis-acl.conf:/usr/local/etc/redis/redis.conf'],
            $result['services']['shlink_redis_acl']['volumes']
        );
        $this->assertArrayNotHasKey('shlink_swagger_ui', $result['services']);
    }

    /**
     * Digiboard's compose: a Traefik that discovers containers through the
     * Docker socket. The account never gets the socket and its own proxy does
     * the routing, so keeping it left a container retrying the provider forever.
     */
    public function test_a_bundled_ingress_proxy_is_not_kept_as_a_runtime_service(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          traefik:
            image: traefik:v3.5
            command:
              - --providers.docker=true
              - --entrypoints.web.address=:80
            ports:
              - "80:80"
            volumes:
              - /var/run/docker.sock:/var/run/docker.sock:ro
              - traefik_letsencrypt:/letsencrypt
          app:
            build: .
            depends_on:
              - traefik
              - redis
            labels:
              - traefik.http.services.app.loadbalancer.server.port=3000
          redis:
            image: redis:7-alpine
            depends_on:
              - traefik
            volumes:
              - redis-data:/data
          proxy:
            image: nginxproxy/nginx-proxy:1.6
        volumes:
          traefik_letsencrypt:
          redis-data:
        YAML, false);

        $this->assertSame(['redis'], array_keys($result['services']));
        $this->assertSame(['traefik (traefik:v3.5)', 'proxy (nginxproxy/nginx-proxy:1.6)'], $result['dropped_proxies']);
        $this->assertArrayNotHasKey('traefik_letsencrypt', $result['volumes']);
        $this->assertArrayNotHasKey('depends_on', $result['services']['redis']);
    }

    /** A Traefik routing the stack's own paths from a file provider is part of the app, as on the compose strategy. */
    public function test_a_traefik_that_routes_the_stacks_own_paths_is_kept(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          router:
            image: traefik:v3.1
            command: --providers.file.filename=/etc/traefik/dynamic.yml
          app:
            build: .
          db:
            image: postgres:16
        YAML, false);

        $this->assertSame(['router', 'db'], array_keys($result['services']));
        $this->assertSame([], $result['dropped_proxies']);
    }

    /** What the hardener removes from a kept service comes back, for the deploy log. */
    public function test_a_kept_sidecars_removed_mounts_are_reported(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          app:
            build: .
          redis:
            image: redis:7-alpine
            volumes:
              - /var/run/docker.sock:/var/run/docker.sock
              - redis-data:/data
        volumes:
          redis-data:
        YAML, false);

        $this->assertSame(['redis-data:/data'], $result['services']['redis']['volumes']);
        $this->assertSame(['redis: volume /var/run/docker.sock:/var/run/docker.sock'], $result['hardening_removed']);
    }

    public function test_a_file_with_no_services_contributes_nothing(): void
    {
        $empty = ['services' => [], 'volumes' => [], 'env' => [], 'app_env' => [], 'app_mounts' => [], 'build_image' => null, 'app_aliases' => [], 'published_secrets' => []];

        $this->assertSame($empty, $this->extract("volumes:\n  dbdata:\n"));
        $this->assertSame($empty, $this->extract(''));
    }

    public function test_unparseable_yaml_contributes_nothing_rather_than_throwing(): void
    {
        // A broken compose file is the project's problem. It must not be an
        // exception out of the deploy.
        $this->assertSame(
            ['services' => [], 'volumes' => [], 'env' => [], 'app_env' => [], 'app_mounts' => [], 'build_image' => null, 'app_aliases' => [], 'published_secrets' => []],
            $this->extract("services:\n  app:\n   - [\n")
        );
    }

    public function test_a_missing_file_contributes_nothing(): void
    {
        $this->assertSame(
            ['services' => [], 'volumes' => [], 'env' => [], 'app_env' => [], 'app_mounts' => [], 'build_image' => null, 'app_aliases' => [], 'published_secrets' => []],
            RuntimeSidecars::fromFile('/nonexistent/compose.yaml')
        );
    }

    public function test_a_file_is_read_from_disk(): void
    {
        $path = sys_get_temp_dir() . '/pa-sidecars-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, self::STACK);

        try {
            $result = RuntimeSidecars::fromFile($path, true, null, 'github.com/acme/shop');

            $this->assertSame(['db'], array_keys($result['services']));
        } finally {
            unlink($path);
        }
    }

    /** Shaped after github.com/ovh/utask's docker-compose.yaml. */
    private const UTASK = <<<'YAML'
    services:
      utask:
        build: .
        command: ["/wait-for-it/wait-for-it.sh", "db:5432", "--", "/app/utask"]
        volumes:
          - "./templates:/app/templates:ro"
        ports:
          - "8081:8081"
        depends_on: [db]
        environment:
          CONFIGURATION_FROM: env:CFG
          CFG_DATABASE: postgres://user:pass@db/utask?sslmode=disable
          CFG_CALLBACK_CONFIG: '{"base_url": "http://localhost:8081", "signing_key": "abc"}'
          CFG_LOG_LEVEL: ${LOG_LEVEL:-info}
          CFG_SOURCE: ${SOURCE}
          PORT: "8081"
      vite:
        build: .
        command: npx vite dev
        volumes:
          - ./:/app
        environment:
          VITE_API_URL: http://localhost:8081
      mailpit:
        image: axllent/mailpit
        environment:
          MP_SMTP_AUTH_ACCEPT_ANY: "1"
      db:
        image: postgres:9.6-alpine
        environment:
          POSTGRES_USER: user
          POSTGRES_PASSWORD: pass
          POSTGRES_DB: utask
    YAML;

    public function test_the_workstation_app_services_environment_is_harvested(): void
    {
        // The Dockerfile build replaces the dropped `utask` service, so it
        // needs that service's config. Unresolvable and reserved keys stay out.
        $result = RuntimeSidecars::fromYaml(self::UTASK, false, null, 'github.com/ovh/utask');

        $this->assertSame(['db'], array_keys($result['services']));
        $this->assertSame([
            'CONFIGURATION_FROM' => 'env:CFG',
            'CFG_DATABASE' => 'postgres://user:pass@db/utask?sslmode=disable',
            'CFG_CALLBACK_CONFIG' => '{"base_url": "http://localhost:8081", "signing_key": "abc"}',
            'CFG_LOG_LEVEL' => 'info',
        ], $result['app_env']);
    }

    public function test_a_test_variant_of_the_app_is_not_harvested(): void
    {
        // Zerobyte: every service builds the repo; the e2e ones turn rate
        // limiting off and point Node at a test CA.
        $yaml = <<<'YAML'
        services:
          zerobyte-dev:
            build: .
            environment:
              - NODE_ENV=development
            volumes:
              - ./app:/app/app
          zerobyte-e2e:
            build: .
            environment:
              - DISABLE_RATE_LIMITING=true
              - NODE_EXTRA_CA_CERTS=/tinyauth-ca/root.crt
            volumes:
              - ./playwright/data:/var/lib/zerobyte/data
          zerobyte_test:
            build: .
            environment:
              - TRUSTED_ORIGINS=https://localhost:5557
            volumes:
              - .:/app
        YAML;
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/nicotsx/zerobyte');

        $this->assertSame(['NODE_ENV' => 'development'], $result['app_env']);
    }

    public function test_a_secret_like_key_with_a_literal_value_is_flagged_as_published(): void
    {
        // Zerobyte's production variant ships its own signing secret in the
        // repository; kept verbatim, every account that deploys it gets the
        // exact same value, so the caller needs to know which key and service.
        $yaml = <<<'YAML'
        services:
          zerobyte-prod:
            build: .
            image: nicotsx/zerobyte:latest
            environment:
              - APP_SECRET=94bad46abe2c1d9f
              - LOG_LEVEL=debug
        YAML;
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/nicotsx/zerobyte');

        $this->assertSame('94bad46abe2c1d9f', $result['app_env']['APP_SECRET']);
        $this->assertSame(['zerobyte-prod' => ['APP_SECRET']], $result['published_secrets']);
    }

    public function test_a_required_secret_reference_is_not_flagged_as_published(): void
    {
        // Nothing in UTASK's harvested env is a secret-like key with a literal
        // value — the regression this must not fire on.
        $result = RuntimeSidecars::fromYaml(self::UTASK, false, null, 'github.com/ovh/utask');

        $this->assertSame([], $result['published_secrets']);
    }

    public function test_dev_sidecars_are_not_harvested(): void
    {
        // vite also builds and bind-mounts the repo, but it is not the app.
        $result = RuntimeSidecars::fromYaml(self::UTASK, false, null, 'github.com/ovh/utask');
        $all = $result['env'] + $result['app_env'];

        $this->assertArrayNotHasKey('VITE_API_URL', $all);
        $this->assertArrayNotHasKey('MP_SMTP_AUTH_ACCEPT_ANY', $all);
    }

    public function test_a_literal_database_password_survives_pinning_so_the_harvested_url_still_works(): void
    {
        // Pinning only settles `${VAR}` credentials; a literal is kept as
        // written, so the password inside CFG_DATABASE still matches.
        $result = RuntimeSidecars::fromYaml(self::UTASK, false, null, 'github.com/ovh/utask');
        $db = $result['services']['db']['environment'];
        $url = parse_url($result['app_env']['CFG_DATABASE']);

        $this->assertSame('pass', $db['POSTGRES_PASSWORD']);
        $this->assertSame($db['POSTGRES_PASSWORD'], $url['pass']);
        $this->assertSame($db['POSTGRES_USER'], $url['user']);
    }

    public function test_a_url_built_on_a_password_that_pinning_invents_is_not_harvested(): void
    {
        // A bare `${POSTGRES_PASSWORD}` is pinned to a fallback, so a URL that
        // embeds the same reference would carry the wrong one. It is dropped;
        // the sidecar's own DATABASE_URL carries the pinned password.
        $yaml = str_replace(
            ['POSTGRES_PASSWORD: pass', 'user:pass@db'],
            ['POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}', 'user:${POSTGRES_PASSWORD}@db'],
            self::UTASK
        );
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/ovh/utask');
        $pinned = $result['services']['db']['environment']['POSTGRES_PASSWORD'];

        $this->assertArrayNotHasKey('CFG_DATABASE', $result['app_env']);
        $this->assertSame($pinned, parse_url($result['env']['DATABASE_URL'])['pass']);
    }

    public function test_a_required_secret_the_app_and_the_database_share_is_the_same_on_both(): void
    {
        $yaml = str_replace(
            ['POSTGRES_PASSWORD: pass', 'CFG_SOURCE: ${SOURCE}'],
            ['POSTGRES_PASSWORD: ${DB_PASSWORD:?required}', 'DB_PASSWORD: ${DB_PASSWORD:?required}'],
            self::UTASK
        );
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/ovh/utask', null, 'seed');
        $expected = ComposePlaceholders::generatedSecret('DB_PASSWORD', 'seed');

        $this->assertSame($expected, $result['app_env']['DB_PASSWORD']);
        $this->assertSame($expected, $result['services']['db']['environment']['POSTGRES_PASSWORD']);
    }

    public function test_harvested_app_env_ranks_below_the_strategy_the_sidecars_and_the_account(): void
    {
        // A workstation APP_ENV=local must not override the production value
        // the strategy generates.
        $merger = new DindRuntimeSidecars($this->dindForMerger());
        $decision = $merger->mergeRuntimeSidecars(
            ['env' => ['APP_ENV' => 'production']],
            [
                'services' => ['db' => ['image' => 'postgres:16']],
                'volumes' => [],
                'env' => ['CFG_DATABASE' => 'from-sidecar'],
                'app_env' => ['APP_ENV' => 'local', 'CFG_DATABASE' => 'from-app', 'CFG_A' => 'app', 'CFG_B' => 'app'],
            ]
        );
        $env = ComposeEnvironment::layer($decision, [], ['CFG_B' => 'account'])['env'];

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('from-sidecar', $env['CFG_DATABASE']);
        $this->assertSame('app', $env['CFG_A']);
        $this->assertSame('account', $env['CFG_B']);
    }

    public function test_the_apps_own_url_to_a_kept_sidecar_keeps_its_driver(): void
    {
        // CTFd: the generic mysql:// made SQLAlchemy load MySQLdb, which the
        // image does not ship.
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          ctfd:
            build: .
            environment:
              - DATABASE_URL=mysql+pymysql://ctfd:ctfd@db/ctfd
              - REDIS_URL=redis://cache:6379
            volumes:
              - .:/opt/CTFd
          db:
            image: mariadb:10.11
            environment:
              - MARIADB_ROOT_PASSWORD=ctfd
              - MARIADB_USER=ctfd
              - MARIADB_PASSWORD=ctfd
              - MARIADB_DATABASE=ctfd
          cache:
            image: redis:4
        YAML, false, null, 'github.com/ctfd/ctfd');
        $merger = new DindRuntimeSidecars($this->dindForMerger());
        $env = $merger->mergeRuntimeSidecars(['env' => []], $result)['env'];

        $this->assertSame('mysql+pymysql://ctfd:ctfd@db/ctfd', $env['DATABASE_URL']);
        $this->assertSame('redis://cache:6379', $env['REDIS_URL']);
        // What the app did not declare still comes from the sidecar.
        $this->assertArrayHasKey('MARIADB_URL', $env);

        // A URL whose password the sidecar does not run with is not kept.
        $result['app_env']['DATABASE_URL'] = 'mysql+pymysql://ctfd:other@db/ctfd';
        $env = $merger->mergeRuntimeSidecars(['env' => []], $result)['env'];
        $this->assertSame('mysql://ctfd:ctfd@db:3306/ctfd', $env['DATABASE_URL']);
    }

    public function test_ports_the_image_declares_are_consulted(): void
    {
        // A datastore whose compose file publishes nothing is still
        // recognisable from what its image exposes.
        $result = RuntimeSidecars::fromYaml(
            "services:\n  store:\n    image: internal/store:1\n  app:\n    image: ghcr.io/acme/shop:latest\n",
            true,
            static fn (string $image): array => $image === 'internal/store:1' ? [5432] : [],
            'github.com/acme/shop'
        );

        $this->assertSame(['store'], array_keys($result['services']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ourShoppingListImages(): array
    {
        return [
            'the repo as shipped' => ['${CONTAINER_BUILD_IMAGE}:latest'],
            'its published image' => ['nanawel/our-shopping-list:latest'],
        ];
    }

    #[DataProvider('ourShoppingListImages')]
    public function test_a_service_built_from_the_repo_is_the_app_not_a_sidecar(string $image): void
    {
        // our-shopping-list: its `app` was kept beside the generated one and
        // the deploy died on `dependency cycle detected: app -> app`.
        $yaml = <<<YAML
        services:
          app:
            image: {$image}
            build:
              context: './'
              args:
                build_version: 'dev'
        YAML;
        $result = RuntimeSidecars::fromYaml($yaml, true, null, 'nanawel/our-shopping-list');

        $this->assertSame([], $result['services']);
    }

    public function test_a_built_app_is_dropped_even_without_the_project_identity(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          app:
            image: someone/else:latest
            build: .
            depends_on: [db]
          db:
            image: postgres:16
        YAML, false);

        $this->assertSame(['db'], array_keys($result['services']));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function repoUrls(): array
    {
        return [
            'codeberg https' => ['https://codeberg.org/nanawel/our-shopping-list', 'nanawel/our-shopping-list'],
            'gitlab https .git' => ['https://gitlab.com/acme/shop.git', 'acme/shop'],
            'gitlab subgroup' => ['https://gitlab.com/acme/tools/shop/', 'tools/shop'],
            'gitea with credentials' => ['https://user:token@gitea.example.com/acme/shop.git', 'acme/shop'],
            'scp-style ssh' => ['git@codeberg.org:nanawel/our-shopping-list.git', 'nanawel/our-shopping-list'],
            'ssh url with port' => ['ssh://git@gitlab.example.com:2222/acme/shop.git', 'acme/shop'],
            'no owner' => ['https://example.com/shop.git', null],
        ];
    }

    #[DataProvider('repoUrls')]
    public function test_the_project_identity_is_read_from_any_forge_url(string $url, ?string $expected): void
    {
        $model = $this->createStub(\App\Models\User::class);
        $model->method('getGitRepo')->willReturn($url);
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);

        $identity = (new \ReflectionMethod(DindRuntimeSidecars::class, 'projectIdentity'))
            ->invoke(new DindRuntimeSidecars($dind));

        $this->assertSame($expected, $identity);
    }

    public function test_an_image_sidecar_named_app_is_renamed_not_merged(): void
    {
        // A genuine redis called `app` would collide with the generated app;
        // it moves aside and everything that pointed at it follows.
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          app:
            image: redis:7
          ui:
            image: rediscommander/redis-commander
            depends_on: [app]
            links: ["app:cache"]
        YAML, false);

        $this->assertSame(['app-sidecar', 'ui'], array_keys($result['services']));
        $this->assertSame(['app-sidecar'], $result['services']['ui']['depends_on']);
        $this->assertSame(['app-sidecar:cache'], $result['services']['ui']['links']);
        $this->assertSame('app-sidecar', $result['env']['REDIS_HOST']);
    }

    public function test_the_merge_never_puts_a_sidecar_under_the_app_name(): void
    {
        $merger = new DindRuntimeSidecars($this->dindForMerger());
        $decision = $merger->mergeRuntimeSidecars([], [
            'services' => [
                'app' => ['image' => 'nanawel/our-shopping-list:latest'],
                'db' => ['image' => 'postgres:16', 'depends_on' => ['app']],
            ],
            'volumes' => [],
            'env' => [],
        ]);

        $this->assertSame(['db'], array_keys($decision['sidecars']));
        $this->assertSame(['db'], $decision['depends_on']);
        $this->assertArrayNotHasKey('depends_on', $decision['sidecars']['db']);
    }

    /**
     * Bolt's shape: a kept nginx in front reaches the application by the
     * service name inside its own config — `fastcgi_pass php:9000` — so the
     * generated `app` has to answer to `php` as well.
     *
     * The stack restart-looped with
     *
     *     nginx: [emerg] host not found in upstream "php" in
     *     /etc/nginx/conf.d/default.conf:22
     *
     * because the engine dropped the file's `php` service and built its own
     * under `app`, and nothing rewrote the reference: it is in a config file
     * the engine does not generate. The same line appeared for Bitpoll, CTFd
     * and Offen, which is a general fault rather than a bad repository.
     */
    public function test_a_kept_proxy_names_the_app_it_fronted(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          php:
            build:
              context: docker/php
              dockerfile: Dockerfile
            depends_on: [database]
          database:
            image: mariadb:10
          web:
            image: nginx:alpine
            depends_on: [php]
        YAML, false);

        $this->assertSame(['database', 'web'], array_keys($result['services']));
        // `php` is what `web`'s own config reaches; `app` is what the build is
        // called and needs no alias.
        $this->assertSame(['php'], $result['app_aliases']);
    }

    public function test_a_files_app_is_named_by_a_kept_sibling_even_when_it_comes_first(): void
    {
        // Compose files are often written app-first, which used to decide
        // whether the alias was collected at all.
        $result = $this->extract(<<<'YAML'
        services:
          ctfd:
            build: .
            ports: ["8000:8000"]
          nginx:
            image: nginx:stable
            depends_on: [ctfd]
        YAML, false);

        $this->assertSame(['nginx'], array_keys($result['services']));
        $this->assertSame(['ctfd'], $result['app_aliases']);
    }

    /** A name its configuration may have been written against instead. */
    public function test_a_container_name_is_carried_as_an_alias_too(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          app:
            build: .
            container_name: auditorium
          proxy:
            image: nginx:1.17-alpine
        YAML, false);

        // `app` is the name the generated service already answers to; only
        // the container name is an alias it would otherwise not have.
        $this->assertSame(['auditorium'], $result['app_aliases']);
    }

    /**
     * An alias a kept service already answers to would resolve to two
     * containers, and the proxy would round-robin the application with its own
     * database. The kept name is the one the file was written against.
     */
    public function test_an_alias_a_kept_service_already_answers_to_is_not_taken(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          php:
            build: .
            depends_on: [database]
          database:
            image: mariadb:10
          web:
            image: nginx:alpine
            depends_on: [php]
        YAML, false);

        $this->assertSame(['database', 'web'], array_keys($result['services']));
        $this->assertSame(['php'], $result['app_aliases']);
    }

    /**
     * vite builds the same repository but runs a dev server over the source,
     * not the application the engine deploys. Aliasing its name to the app
     * would point a proxy at something that was never there.
     */
    public function test_a_built_dev_sidecar_is_not_an_application_name(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          utask:
            build: .
            command: ["/app/utask"]
          vite:
            build: .
            command: npx vite dev
          db:
            image: postgres:16
        YAML, false);

        $this->assertSame(['utask'], $result['app_aliases']);
    }

    /**
     * An alias is not dead weight: with no kept service to name it, there is
     * no sibling reference for it to resolve and nothing to put on the network.
     */
    public function test_nothing_kept_means_no_alias(): void
    {
        // No built service at all, so nothing this deploy replaces.
        $result = $this->extract(<<<'YAML'
        services:
          database:
            image: mariadb:10
        YAML, false);

        $this->assertSame(['database'], array_keys($result['services']));
        $this->assertSame([], $result['app_aliases']);
    }

    /** The application's own name `app` needs no alias — the build already has it. */
    public function test_an_app_service_named_app_gets_no_alias_from_its_own_name(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          app:
            build: .
          db:
            image: postgres:16
        YAML, false);

        $this->assertSame(['db'], array_keys($result['services']));
        $this->assertSame([], $result['app_aliases']);
    }

    /**
     * The merge carries the aliases onto the decision, before its early return
     * for "no sidecars" — because the shape that needs them is a file whose
     * *only* kept service is the proxy in front.
     */
    public function test_the_merge_carries_the_aliases_onto_the_decision(): void
    {
        $merger = new DindRuntimeSidecars($this->dindForMerger());
        $decision = $merger->mergeRuntimeSidecars([], [
            'services' => ['web' => ['image' => 'nginx:alpine']],
            'volumes' => [],
            'env' => [],
            'app_aliases' => ['php'],
        ]);

        $this->assertSame(['php'], $decision['app_aliases']);
        $this->assertSame(['web'], $decision['depends_on']);
    }

    public function test_the_merge_carries_the_aliases_even_with_no_sidecars(): void
    {
        $merger = new DindRuntimeSidecars($this->dindForMerger());
        $decision = $merger->mergeRuntimeSidecars([], [
            'services' => [],
            'volumes' => [],
            'env' => [],
            'app_aliases' => ['php'],
        ]);

        $this->assertSame(['php'], $decision['app_aliases']);
        $this->assertArrayNotHasKey('sidecars', $decision);
    }

    /**
     * The glob for `docker-compose.*.yml` stack slices must not pick up the
     * recipe's own `docker-compose.override.yml` — which the engine copied in
     * and whose `image:`-bearing services would be re-emitted as sidecars.
     */
    public function test_a_recipes_own_compose_override_is_not_read_as_a_stack_template(): void
    {
        $dir = sys_get_temp_dir() . '/pa-sidecars-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents(
            $dir . '/docker-compose.override.yml',
            "services:\n  ready:\n    image: alpine:3\n    command: [\"true\"]\n"
        );
        // A genuine engine slice the glob should still return.
        file_put_contents($dir . '/docker-compose.services.yml', "services:\n  db:\n    image: postgres:16\n");

        try {
            $names = (new \ReflectionMethod(DindRuntimeSidecars::class, 'exampleComposeFilenames'))
                ->invoke(null, $dir);

            $this->assertNotContains('docker-compose.override.yml', $names);
            $this->assertContains('docker-compose.services.yml', $names);
        } finally {
            unlink($dir . '/docker-compose.override.yml');
            unlink($dir . '/docker-compose.services.yml');
            rmdir($dir);
        }
    }

    public function test_mysql_and_redis_sidecars_are_kept_as_before(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          web:
            build: .
            depends_on: [mysql, redis]
          mysql:
            image: mysql:8
            environment:
              MYSQL_DATABASE: shop
              MYSQL_USER: shop
              MYSQL_PASSWORD: secret
          redis:
            image: redis:7
        YAML);

        $this->assertSame(['mysql', 'redis'], array_keys($result['services']));
        $this->assertSame('mysql', $result['env']['MYSQL_HOST'] ?? $result['env']['DB_HOST'] ?? null);
        $this->assertSame('redis', $result['env']['REDIS_HOST']);
    }

    /** godoxy's compose.example.yml (#142): the socket proxy's bind source is a variable. */
    public function test_a_template_bind_mount_through_a_variable_declares_no_volume(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
services:
  socket-proxy:
    image: ghcr.io/yusing/socket-proxy:latest
    environment:
      - CONTAINERS=1
    volumes:
      - ${DOCKER_SOCKET:-/var/run/docker.sock}:/var/run/docker.sock
  app:
    image: ghcr.io/yusing/godoxy:${TAG:-latest}
    network_mode: host
    env_file: .env
YAML, true, null, 'yusing/godoxy');

        $this->assertArrayHasKey('socket-proxy', $result['services']);
        $this->assertSame([], $result['volumes']);
    }

    /**
     * foodsoft's docker-compose.ci.yml (#108): the app service is `image: ${IMAGE}`
     * with no `build:`. Kept, it interpolated to nothing and compose rejected the
     * project: service "foodsoft" has neither an image nor a build context.
     */
    public function test_a_service_whose_image_is_an_unset_variable_is_the_app_not_a_backing_service(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
services:
  mariadb:
    image: mariadb:10.5
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-secret}
      MYSQL_DATABASE: ${MYSQL_DATABASE:-foodsoft}
  redis:
    image: redis:6.2-alpine
  foodsoft:
    image: ${IMAGE}
    environment:
      MYSQL_USER: ${MYSQL_USER:-foodsoft}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:-secret}
      DATABASE_URL: mysql2://foodsoft:secret@mariadb/foodsoft
    networks:
      - proxy
      - internal
networks:
  internal:
  proxy:
    external: true
YAML, true, null, 'foodcoops/foodsoft');

        $this->assertSame(['mariadb', 'redis'], array_keys($result['services']));
    }

    #[DataProvider('imageReferences')]
    public function test_only_an_image_that_resolves_to_nothing_is_dropped(string $image, bool $kept): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  db:\n    image: \"{$image}\"\n    environment:\n      POSTGRES_PASSWORD: x\n",
            true
        );

        $this->assertSame($kept, isset($result['services']['db']));
    }

    public function test_a_harvested_sidecars_mounts_are_checked_as_compose_interpolates_them(): void
    {
        $yaml = <<<'YAML'
        services:
          db:
            image: mysql:8
            volumes:
              - ${DB_DIR}:/var/lib/mysql
              - ${INIT_DIR:-./initdb}:/docker-entrypoint-initdb.d
        YAML;

        $result = RuntimeSidecars::fromYaml($yaml, true, null, 'github.com/acme/shop', null, null, null, ['DB_DIR' => ['/var/lib/docker/volumes']]);

        $this->assertSame(['${INIT_DIR:-./initdb}:/docker-entrypoint-initdb.d'], $result['services']['db']['volumes']);
    }

    /** @return array<string, array{string, bool}> */
    public static function imageReferences(): array
    {
        return [
            'bare variable' => ['${IMAGE}', false],
            'required variable' => ['${IMAGE:?set IMAGE}', false],
            'defaulted variable' => ['${PG_IMAGE:-postgres:16}', true],
            'variable tag with default' => ['postgres:${PG_TAG:-16}', true],
            'plain image' => ['postgres:16', true],
        ];
    }

    /**
     * Meet builds two backend tags; the deploy builds `app`'s, so a worker on
     * the development tag would be pulled from Docker Hub and fail.
     */
    public function test_a_service_on_a_tag_only_a_dropped_builder_makes_is_dropped(): void
    {
        $result = $this->extract(<<<'YAML'
        services:
          app-dev:
            build:
              context: .
              target: backend-development
            image: meet:backend-development
            volumes:
              - ./src/backend:/app
          celery-dev:
            image: meet:backend-development
            command: celery worker
            depends_on: [app-dev, redis]
          app:
            build:
              context: .
              target: backend-production
            image: meet:backend-production
          celery:
            image: meet:backend-production
            command: celery worker
            depends_on: [app, redis]
          redis:
            image: redis:5
        YAML, false);

        $this->assertSame('meet:backend-production', $result['build_image']);
        $this->assertArrayNotHasKey('celery-dev', $result['services']);
        $this->assertArrayHasKey('celery', $result['services']);
        $this->assertArrayHasKey('redis', $result['services']);
    }

    /** LinkAce's docker-compose.production.yml, the part the deploy keeps. */
    private const LINKACE_PRODUCTION = <<<'YAML'
    services:
      app:
        image: docker.io/linkace/linkace:latest
        depends_on: [db, meilisearch]
        ports:
          - "0.0.0.0:80:80"
      db:
        image: docker.io/library/mariadb:12.0
        environment:
          - MYSQL_ROOT_PASSWORD=${DB_PASSWORD}
          - MYSQL_USER=${DB_USERNAME}
          - MYSQL_PASSWORD=${DB_PASSWORD}
          - MYSQL_DATABASE=${DB_DATABASE}
      meilisearch:
        image: docker.io/getmeili/meilisearch:v1.16
        environment:
          - MEILI_ENV=production
          - MEILI_MASTER_KEY=${MEILISEARCH_KEY}
      redis:
        image: docker.io/library/redis:8.2
        command: "redis-server --requirepass ${REDIS_PASSWORD}"
    YAML;

    public function test_a_kept_sidecars_bare_secret_is_generated_for_it_and_the_app(): void
    {
        $result = RuntimeSidecars::fromYaml(self::LINKACE_PRODUCTION, true, null, 'github.com/kovah/linkace', null, 'account-seed');

        $redisPassword = ComposePlaceholders::requiredSecretValue('REDIS_PASSWORD', 'account-seed');
        $meiliKey = ComposePlaceholders::requiredSecretValue('MEILISEARCH_KEY', 'account-seed');
        $this->assertSame('redis-server --requirepass ' . $redisPassword, $result['services']['redis']['command']);
        $this->assertSame($meiliKey, $result['services']['meilisearch']['environment']['MEILI_MASTER_KEY']);
        $this->assertGreaterThanOrEqual(16, strlen($meiliKey));
        // The app reads the same names the file interpolated, and the URL authenticates.
        $this->assertSame($redisPassword, $result['env']['REDIS_PASSWORD']);
        $this->assertSame($meiliKey, $result['env']['MEILISEARCH_KEY']);
        $this->assertSame('redis://:' . $redisPassword . '@redis:6379/0', $result['env']['REDIS_URL']);
    }

    public function test_a_bare_secret_the_projects_env_sets_is_left_for_compose(): void
    {
        $result = RuntimeSidecars::fromYaml(
            self::LINKACE_PRODUCTION,
            true,
            null,
            'github.com/kovah/linkace',
            null,
            'account-seed',
            null,
            ['REDIS_PASSWORD' => ['from-dotenv']]
        );

        $this->assertSame('redis-server --requirepass ${REDIS_PASSWORD}', $result['services']['redis']['command']);
        $this->assertArrayNotHasKey('REDIS_PASSWORD', $result['env']);
        $this->assertNotSame('', $result['services']['meilisearch']['environment']['MEILI_MASTER_KEY']);
    }

    public function test_a_bare_reference_that_names_no_secret_is_left_alone(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  redis:\n    image: redis:7\n    command: redis-server --maxmemory \${REDIS_MAXMEMORY}\n",
            true,
            null,
            null,
            null,
            'account-seed'
        );

        $this->assertSame('redis-server --maxmemory ${REDIS_MAXMEMORY}', $result['services']['redis']['command']);
    }
}
