<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;

/**
 * The port the proxy is pointed at, read out of the repository's own compose
 * file.
 *
 * Getting this wrong is the difference between a working site and a
 * connection reset, and the failure is invisible from the deploy log: every
 * container is up and healthy, the proxy is just talking to the wrong one.
 */
class ComposePortScanTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-ports-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function compose(string $yaml): string
    {
        $path = $this->dir . '/compose.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    public function test_a_single_published_port_is_the_primary(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "8080:8080"
        YAML);

        $this->assertSame(['all' => [8080], 'primary' => 8080, 'refused' => []], ComposePortScan::of($path));
    }

    public function test_the_preferred_port_wins_over_a_lower_number(): void
    {
        // 3000 is numerically smaller, but 80 is what a browser will ask for.
        $path = $this->compose(<<<'YAML'
        services:
          web:
            image: nginx
            ports:
              - "80:80"
          api:
            image: acme/api
            ports:
              - "3000:3000"
        YAML);

        $this->assertSame([80, 3000], ComposePortScan::of($path)['all']);
    }

    public function test_unpreferred_ports_sort_numerically_after_preferred_ones(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "9999:9999"
              - "4200:4200"
              - "8000:8000"
        YAML);

        $this->assertSame([8000, 4200, 9999], ComposePortScan::of($path)['all']);
    }

    /** Haraka's `EXPOSE 25` was taken for the site (engine#88). */
    public function test_a_non_web_port_sorts_after_every_other(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          mail:
            image: haraka/haraka
            expose:
              - "25"
              - "2222"
          app:
            image: acme/app
            ports:
              - "9999:9999"
        YAML);

        $this->assertSame([9999, 25, 2222], ComposePortScan::of($path)['all']);
        $this->assertSame(9999, ComposePortScan::primaryOf($path));
    }

    public function test_a_lone_non_web_port_is_still_offered(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          smtp:
            image: haraka/haraka
            expose:
              - "25"
        YAML);

        $this->assertSame(25, ComposePortScan::primaryOf($path));
    }

    public function test_a_database_port_is_not_offered(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "8080:8080"
          db:
            image: postgres:16
            ports:
              - "5432:5432"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([8080], $scan['all']);
        // Not offered, but not silently dropped either: a caller asking why
        // 5432 is missing gets an answer instead of an absence.
        $this->assertSame(
            [['port' => 5432, 'reason' => 'datastore', 'service' => 'PostgreSQL']],
            $scan['refused']
        );
    }

    public function test_a_remapped_database_is_still_not_offered(): void
    {
        // Host 5434 looks like an ordinary port. The container side and the
        // image both say otherwise.
        $path = $this->compose(<<<'YAML'
        services:
          db:
            image: postgres:16
            ports:
              - "5434:5432"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([], $scan['all']);
        $this->assertSame(
            [['port' => 5434, 'reason' => 'datastore', 'service' => 'PostgreSQL']],
            $scan['refused']
        );
    }

    public function test_expose_is_read_when_nothing_is_published(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            expose:
              - "3000"
        YAML);

        $this->assertSame(3000, ComposePortScan::primaryOf($path));
    }

    public function test_a_loopback_binding_offers_nothing(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "127.0.0.1:8080:8080"
        YAML);

        // A loopback binding is not a mapping at all, so there is nothing to
        // report as refused either.
        $this->assertSame(['all' => [], 'refused' => []], ComposePortScan::of($path));
    }

    public function test_an_env_var_default_is_honoured(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "${APP_PORT:-8090}:8000"
        YAML);

        $this->assertSame(8090, ComposePortScan::primaryOf($path));
    }

    /** BorgWarehouse's own compose file, as published; it was routed to 8080 and answered 502. */
    public function test_a_required_host_port_variable_is_read_as_the_container_port(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          borgwarehouse:
            container_name: borgwarehouse
            image: borgwarehouse/borgwarehouse
            ports:
              - '${WEB_SERVER_PORT:?WEB_SERVER_PORT variable missing}:3000'
              - '${SSH_SERVER_PORT:?SSH_SERVER_PORT variable missing}:22'
            env_file:
              - .env
          apprise:
            container_name: apprise
            image: caronc/apprise
            user: 'www-data:www-data'
        YAML);

        $this->assertSame(['all' => [3000, 22], 'primary' => 3000, 'refused' => []], ComposePortScan::of($path));
    }

    public function test_duplicate_ports_across_services_appear_once(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          blue:
            image: acme/app
            ports:
              - "8080:8080"
          green:
            image: acme/app
            ports:
              - "8080:8080"
        YAML);

        $this->assertSame([8080], ComposePortScan::of($path)['all']);
    }

    public function test_a_stack_that_publishes_nothing_falls_back_to_the_default(): void
    {
        // Common for repos whose compose relies on an external proxy. 8080 is
        // what the engine's generated entrypoint listens on.
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
        YAML);

        $this->assertSame(['all' => [], 'refused' => []], ComposePortScan::of($path));
        $this->assertSame(8080, ComposePortScan::primaryOf($path));
    }

    public function test_a_missing_file_falls_back_to_the_default(): void
    {
        $this->assertSame(['all' => [], 'refused' => []], ComposePortScan::of($this->dir . '/nothing.yaml'));
        $this->assertSame(8080, ComposePortScan::primaryOf($this->dir . '/nothing.yaml'));
    }

    public function test_unparseable_yaml_falls_back_to_the_default(): void
    {
        // A broken compose file is the project's problem, but it must not be
        // an exception out of detection.
        $path = $this->compose("services:\n  app:\n   - ports: [\n");

        $this->assertSame(8080, ComposePortScan::primaryOf($path));
    }

    /**
     * engine#214: zabbix/zabbix-web-nginx-mysql is the frontend, not the
     * database -- its MYSQL_* env names the one it connects to. It read as a
     * datastore, its only port was refused, and the proxy fell back to a port
     * nothing listens on. Filtering must never empty the set.
     */
    public function test_a_web_image_named_after_its_datastore_keeps_its_port(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          web:
            image: zabbix/zabbix-web-nginx-mysql:alpine-6.4-latest
            ports:
              - "8081:8080"
            environment:
              DB_SERVER_HOST: db
              MYSQL_USER: zabbix
              MYSQL_PASSWORD: zabbix_pwd
              MYSQL_DATABASE: zabbix
        YAML);

        $this->assertSame(['all' => [8081], 'primary' => 8081, 'refused' => []], ComposePortScan::of($path));
    }

    /** The rescue is a last resort: with a web port standing, datastores stay refused. */
    public function test_a_real_datastore_beside_a_web_service_is_still_refused(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          web:
            image: zabbix/zabbix-web-nginx-mysql:alpine-6.4-latest
            ports:
              - "8081:8080"
            environment:
              DB_SERVER_HOST: db
              MYSQL_USER: zabbix
              MYSQL_PASSWORD: zabbix_pwd
              MYSQL_DATABASE: zabbix
          db:
            image: mysql:8.0
            ports:
              - "3306:3306"
            environment:
              MYSQL_ROOT_PASSWORD: secret
              MYSQL_DATABASE: zabbix
          store:
            image: postgres:16
            ports:
              - "5432:5432"
        YAML);

        $scan = ComposePortScan::of($path);
        $this->assertSame([8081], $scan['all']);
        $this->assertSame([3306, 5432], array_column($scan['refused'], 'port'));
    }

    /** A datastore's own port is never rescued, even when nothing else is left. */
    public function test_a_lone_datastore_on_its_own_port_is_not_rescued(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          db:
            image: mysql:8.0
            ports:
              - "3306:3306"
        YAML);

        $scan = ComposePortScan::of($path);
        $this->assertSame([], $scan['all']);
        $this->assertArrayNotHasKey('primary', $scan);
        $this->assertSame('datastore', $scan['refused'][0]['reason']);
    }

    public function test_a_non_mapping_service_entry_is_ignored(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app: null
          web:
            image: nginx
            ports:
              - "80:80"
        YAML);

        $this->assertSame([80], ComposePortScan::of($path)['all']);
    }

    /**
     * A datastore remapped onto an ordinary-looking port is refused on the
     * image, so the reason names the image rather than a well-known port.
     */
    public function test_a_datastore_on_an_ordinary_port_is_refused_by_image(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          db:
            image: postgres:16
            ports:
              - "8081:8081"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([], $scan['all']);
        $this->assertSame(
            [['port' => 8081, 'reason' => 'datastore_image', 'service' => 'postgres:16']],
            $scan['refused']
        );
    }

    /** A port the app publishes is not refused because a datastore names it too. */
    public function test_a_port_another_service_publishes_is_not_reported_as_refused(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "8080:8080"
          db:
            image: postgres:16
            ports:
              - "8080:5432"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([8080], $scan['all']);
        $this->assertSame([], $scan['refused']);
    }

    /** PhantomBot's long-syntax port was invisible, so the default 8080 was used. */
    public function test_a_long_syntax_port_is_published(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          phantombot:
            image: gameflixtv/phantombot
            ports:
              - target: 25000
                published: 25000
                protocol: tcp
                mode: host
        YAML);

        $this->assertSame(['all' => [25000], 'primary' => 25000, 'refused' => []], ComposePortScan::of($path));
    }

    /** a profiled service never starts, so its preferred 8080 is not the site. */
    public function test_a_service_behind_a_profile_offers_no_port(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          telegram-files:
            image: ghcr.io/jarvis2f/telegram-files
            ports: ["${PORT:-6543}:80"]
          telegram-files-qbittorrent:
            image: lscr.io/linuxserver/qbittorrent
            profiles: [share]
            # as the hardened run file has it, loopback removed
            ports:
              - "8080:8080"
              - "${PEER_LISTEN_PORT:-51413}:51413/udp"
        YAML);

        $this->assertSame(['all' => [6543], 'primary' => 6543, 'refused' => []], ComposePortScan::of($path));
    }

    /** Profilarr's parser only exposes 5000; the app publishes 6868. */
    public function test_an_expose_only_port_ranks_after_a_published_one(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          profilarr:
            image: ghcr.io/dictionarry-hub/profilarr:2.2.0
            ports: ['6868:6868']
          parser:
            image: ghcr.io/dictionarry-hub/profilarr-parser:2.2.0
            expose: ['5000']
        YAML);

        $this->assertSame([6868, 5000], ComposePortScan::of($path)['all']);
    }

    /** With nothing published anywhere, expose: still decides by preference. */
    public function test_expose_only_stacks_still_rank_by_preference(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          api:
            image: acme/api
            expose: ['9999']
          web:
            image: acme/web
            expose: ['3000']
        YAML);

        $this->assertSame([3000, 9999], ComposePortScan::of($path)['all']);
    }

    /** 9Router publishes 20128 and depends on headroom, which publishes 8787. */
    public function test_a_dependency_of_a_publishing_service_is_not_the_site(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          9router:
            image: decolua/9router
            ports: ["20128:20128"]
            depends_on: [headroom]
          headroom:
            image: acme/headroom
            ports: ["8787:8787"]
        YAML);

        $this->assertSame([20128, 8787], ComposePortScan::of($path)['all']);
    }

    /** A front proxy depends on the app; the proxy is the site, not the app behind it. */
    public function test_a_front_proxy_wins_over_the_app_it_depends_on(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          proxy:
            image: acme/proxy
            ports: ["8088:8088"]
            depends_on:
              app:
                condition: service_healthy
          app:
            image: acme/app
            ports: ["80:80"]
        YAML);

        $this->assertSame(8088, ComposePortScan::primaryOf($path));
    }

    /** A worker that publishes nothing does not push the app it depends on back. */
    public function test_a_dependent_without_ports_does_not_demote(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports: ["8080:8080"]
          worker:
            image: acme/app
            depends_on: [app]
          admin:
            image: acme/admin
            ports: ["9999:9999"]
        YAML);

        $this->assertSame([8080, 9999], ComposePortScan::of($path)['all']);
    }

    /** Stepifi's web server is 3000 inside, published on 3169; 3001 serves nothing. */
    public function test_a_published_port_ranks_by_its_container_port(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            build: .
            ports:
              - "${PORT:-3169}:3000"
              - "${BULL_BOARD_PORT:-3001}:3001"
        YAML);

        $this->assertSame([3169, 3001], ComposePortScan::of($path)['all']);
    }

    /** The service's own healthcheck names its web port; its other ports follow. */
    public function test_the_port_the_healthcheck_probes_wins_within_its_service(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "5004:5004"
              - "6077:6077"
            healthcheck:
              test: ["CMD", "curl", "-f", "http://localhost:6077/health"]
        YAML);

        $this->assertSame([6077, 5004], ComposePortScan::of($path)['all']);
    }

    /**
     * Why the primary port leads: Cabernet's 5004 (a stream) over 6077 (its
     * web UI) is only the lower number, and a probe may still correct it;
     * Stepifi's and a healthchecked service's are not guesses.
     */
    public function test_the_choice_says_when_the_primary_is_only_the_lowest_number(): void
    {
        $cabernet = $this->compose(<<<'YAML'
        services:
          cabernet:
            image: ghcr.io/cabernetwork/cabernet
            ports:
              - "6077:6077" # Web Interface Port
              - "5004:5004" # Port used to stream
        YAML);
        $this->assertSame(5004, ComposePortScan::of($cabernet)['primary']);
        $this->assertSame(['reason' => ComposePortScan::CHOSEN_LOWEST, 'alternatives' => [6077]], ComposePortScan::choiceOf($cabernet));

        $stepifi = $this->compose("services:\n  app:\n    build: .\n    ports: ['3169:3000', '3001:3001']\n");
        $this->assertSame(ComposePortScan::CHOSEN_PREFERRED, ComposePortScan::choiceOf($stepifi)['reason'] ?? null);

        $checked = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports: ["5004:5004", "6077:6077"]
            healthcheck:
              test: ["CMD", "curl", "-f", "http://localhost:6077/health"]
        YAML);
        $this->assertSame(ComposePortScan::CHOSEN_HEALTHCHECK, ComposePortScan::choiceOf($checked)['reason'] ?? null);

        // Two services, one port each: nothing within a service to choose between.
        $apart = $this->compose("services:\n  a:\n    image: acme/a\n    ports: ['5004:5004']\n  b:\n    image: acme/b\n    ports: ['6077:6077']\n");
        $this->assertSame(['reason' => ComposePortScan::CHOSEN_SINGLE, 'alternatives' => []], ComposePortScan::choiceOf($apart));
        $this->assertNull(ComposePortScan::choiceOf($this->compose("services:\n  a:\n    image: acme/a\n")));
    }

    /** The healthcheck ranks only within its own service: a front door elsewhere still wins. */
    public function test_a_healthcheck_does_not_outrank_another_services_web_port(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          web:
            image: nginx
            ports: ["80:80"]
          app:
            image: acme/app
            ports: ["4000:4000"]
            healthcheck:
              test: curl -f http://127.0.0.1:4000/
        YAML);

        $this->assertSame([80, 4000], ComposePortScan::of($path)['all']);
    }

    /** OpenCloud serves on 9200, Elasticsearch's port; the image is no datastore. */
    public function test_a_datastore_port_on_an_application_image_is_the_last_resort(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          opencloud:
            image: opencloudeu/opencloud-rolling:8.0.1
            ports: ["3000:9200"]
        YAML);

        $this->assertSame(['all' => [3000], 'primary' => 3000, 'refused' => []], ComposePortScan::of($path));
    }

    /** Beside a real web port the same binding stays refused, and a real datastore never counts. */
    public function test_a_datastore_port_on_an_application_image_does_not_beat_a_web_port(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports: ["8000:8000"]
          search:
            image: acme/search-proxy
            ports: ["9200:9200"]
          es:
            image: elasticsearch:8.14.0
            ports: ["9201:9200"]
        YAML);

        $scan = ComposePortScan::of($path);
        $this->assertSame([8000], $scan['all']);
        $this->assertSame([9200, 9201], array_column($scan['refused'], 'port'));

        $alone = $this->compose("services:\n  es:\n    image: elasticsearch:8.14.0\n    ports: [\"9200:9200\"]\n");
        $this->assertSame([], ComposePortScan::of($alone)['all']);
    }

    /**
     * Poznote: the hardener defaults `${HTTP_WEB_PORT}` to 80, but `.env`
     * (from its .env.template) sets 8040, and compose publishes 8040.
     */
    public function test_a_host_port_variable_is_read_from_the_projects_env(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          webserver:
            image: ghcr.io/timothepoznanski/poznote
            ports:
              - "${HTTP_WEB_PORT:-80}:80"
          mcp-server:
            image: ghcr.io/timothepoznanski/poznote-mcp
            ports:
              - "127.0.0.1:${POZNOTE_MCP_PORT:-8045}:8045"
        YAML);

        $this->assertSame(80, ComposePortScan::of($path)['primary']);
        $this->assertSame(
            ['all' => [8040], 'primary' => 8040, 'refused' => []],
            ComposePortScan::of($path, ['HTTP_WEB_PORT' => '8040', 'POZNOTE_MCP_PORT' => '8046'])
        );
    }

    public function test_env_substitution_follows_composes_operators(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          a:
            image: acme/a
            ports:
              - "${EMPTY:-3000}:3000"
              - "${EMPTY_DASH-3100}:3100"
              - "$BARE:3200"
              - "${UNSET:-3300}:3300"
              - target: 80
                published: "${LONG}"
        YAML);

        $scan = ComposePortScan::of($path, ['EMPTY' => '', 'EMPTY_DASH' => '', 'BARE' => '3201', 'LONG' => '3401']);

        // `:-` takes the default for an empty value, `-` only for an unset one.
        $this->assertEqualsCanonicalizing([3000, 3201, 3300, 3401], $scan['all']);
    }
}
