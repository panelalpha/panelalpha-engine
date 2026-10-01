<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The evidence ranking in SidecarEngine, layer by layer.
 *
 * Each test pins one layer by starving the ones above it, so a change that
 * quietly reorders them fails here rather than in a customer's deploy.
 */
class SidecarEngineResolutionTest extends TestCase
{
    // --- layer 1: the image family ------------------------------------

    public function test_the_image_family_decides_before_anything_else(): void
    {
        $service = [
            'image' => 'postgres:16',
            'environment' => ['MYSQL_DATABASE' => 'x', 'MYSQL_USER' => 'y'],
        ];

        $this->assertSame(
            'postgres',
            SidecarEngine::resolve('db', $service, [3306]),
            'a Postgres image is a Postgres whatever its variables and ports claim'
        );
    }

    public function test_a_digest_pinned_image_still_resolves(): void
    {
        $this->assertSame(
            'postgres',
            SidecarEngine::resolve('x', ['image' => 'postgres@sha256:abcdef0123456789'])
        );
    }

    public function test_a_registry_with_a_port_does_not_break_the_family(): void
    {
        $this->assertSame(
            'mysql',
            SidecarEngine::resolve('x', ['image' => 'registry.internal:5000/mariadb:11'])
        );
    }

    // --- layer 2: variables the service declares -----------------------

    public function test_an_unknown_fork_is_recognised_by_its_init_variables(): void
    {
        $service = [
            'image' => 'myorg/our-own-fork:1',
            'environment' => [
                'POSTGRES_DB' => 'appdb',
                'POSTGRES_USER' => 'appuser',
                'POSTGRES_PASSWORD' => 'pw',
            ],
        ];

        $this->assertSame(
            'postgres',
            SidecarEngine::resolve('db', $service),
            'the POSTGRES_ prefix names the engine; nothing has to recognise the image'
        );
    }

    public function test_a_client_of_a_database_is_not_a_database(): void
    {
        $backup = [
            'image' => 'prodrigestivill/postgres-backup-local:16',
            'environment' => [
                'POSTGRES_HOST' => 'db',
                'POSTGRES_USER' => 'appuser',
                'POSTGRES_PASSWORD' => 'pw',
            ],
        ];

        $this->assertNotSame(
            'postgres',
            SidecarEngine::resolve('backup', $backup),
            'a service told where to connect is a client, not the server'
        );
        $this->assertFalse(SidecarEngine::isKnownDatastore('backup', $backup));
    }

    public function test_an_app_reaching_postgres_through_libpq_variables_is_not_a_database(): void
    {
        // AdventureLog: POSTGRES_* for its client, the host as libpq's PGHOST.
        $app = [
            'image' => 'ghcr.io/seanmorley15/adventurelog:latest',
            'environment' => [
                'POSTGRES_PASSWORD' => 'pw',
                'PGHOST' => 'db',
                'POSTGRES_DB' => 'database',
                'POSTGRES_USER' => 'adventure',
            ],
            'ports' => ['8015:80'],
        ];
        $this->assertNotSame('postgres', SidecarEngine::resolve('app', $app));
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml(
            Yaml::dump(['services' => ['app' => $app, 'db' => ['image' => 'postgis/postgis:16-3.5']]], 4)
        ));

        $withUrl = ['image' => 'acme/api', 'environment' => [
            'MYSQL_USER' => 'u', 'MYSQL_PASSWORD' => 'p', 'DATABASE_URL' => 'mysql://u:p@db/app',
        ]];
        $this->assertNotSame('mysql', SidecarEngine::resolve('api', $withUrl));
    }

    /** Zabbix's frontend names its database host under another prefix. */
    public function test_a_client_told_its_server_under_another_prefix_is_not_a_database(): void
    {
        $web = [
            'image' => 'zabbix/zabbix-web-nginx-mysql:alpine-7.0-latest',
            'environment' => [
                'DB_SERVER_HOST' => 'mysql',
                'MYSQL_USER' => 'zabbix',
                'MYSQL_PASSWORD' => 'zabbix_pwd',
                'MYSQL_DATABASE' => 'zabbix',
            ],
        ];

        $this->assertFalse(SidecarEngine::isKnownDatastore('zabbix-web', $web));
        $this->assertFalse(SidecarEngine::isKnownDatastore('zabbix-server', [
            'image' => 'zabbix/zabbix-server-mysql:alpine-7.0-latest',
            'environment' => ['DB_SERVER_HOST=mysql', 'MYSQL_USER=zabbix', 'MYSQL_PASSWORD=pw'],
        ]));
    }

    /** A wildcard or a local address is the server's own setting, not a client's target. */
    public function test_a_servers_own_host_setting_does_not_make_it_a_client(): void
    {
        $service = [
            'image' => 'myorg/our-own-fork:1',
            'environment' => ['ALLOWED_HOST' => '%', 'MYSQL_USER' => 'u', 'MYSQL_PASSWORD' => 'p', 'BIND_HOST' => 'localhost'],
        ];

        $this->assertSame('mysql', SidecarEngine::resolve('db', $service));
    }

    public function test_one_shared_variable_is_not_enough_to_claim_an_engine(): void
    {
        $service = [
            'image' => 'myorg/reporting:1',
            'environment' => ['POSTGRES_PASSWORD' => 'pw'],
        ];

        $this->assertNotSame('postgres', SidecarEngine::resolve('reports', $service));
    }

    // --- layer 3: ports ------------------------------------------------

    public function test_an_unknown_image_is_recognised_by_the_port_it_declares(): void
    {
        $this->assertSame(
            'postgres',
            SidecarEngine::resolve('storage', ['image' => 'internal/datastore:7'], [5432]),
            'an engine we can speak outranks an image name we can only repeat back'
        );
    }

    public function test_ports_are_read_from_the_compose_file_too(): void
    {
        $service = ['image' => 'internal/kv:2', 'expose' => ['6379']];

        $this->assertSame('redis', SidecarEngine::resolve('kv', $service));
    }

    /**
     * @param string|array<string, mixed> $mapping
     */
    #[DataProvider('portMappings')]
    public function test_the_container_side_of_a_port_mapping_is_the_one_that_counts(
        $mapping,
        ?string $expected
    ): void {
        $service = ['image' => 'internal/thing:1', 'ports' => [$mapping]];

        $this->assertSame($expected, SidecarEngine::resolve('thing', $service));
    }

    /** @return array<string, array{string|array<string, mixed>, ?string}> */
    public static function portMappings(): array
    {
        return [
            'short form' => ['5432', 'postgres'],
            'host and container' => ['15432:5432', 'postgres'],
            'address, host, container' => ['127.0.0.1:13306:3306/tcp', 'mysql'],
            'long form' => [['target' => 27017, 'published' => 27017], 'mongo'],
            'host port only looks like a db' => ['5432:8080', 'thing'],
        ];
    }

    // --- layer 4: the service name -------------------------------------

    /**
     * With nothing else to go on the name is repeated back rather than guessed
     * at. A service called `db` yields `db`, so the application is handed
     * DB_HOST and DB_PORT — true, and more useful than inventing an engine.
     */
    public function test_the_name_is_repeated_back_when_nothing_else_answers(): void
    {
        $this->assertSame('db', SidecarEngine::resolve('db', []));
        $this->assertSame('cache', SidecarEngine::resolve('cache', []));
        $this->assertFalse(SidecarEngine::isKnownDatastore('db', []));
    }

    public function test_a_recognised_image_is_never_overruled_by_the_name(): void
    {
        $this->assertSame(
            'memcached',
            SidecarEngine::resolve('cache', ['image' => 'memcached:1.6']),
            'the name "cache" used to turn a memcached into a Redis on the wrong port'
        );
    }

    public function test_an_unrecognised_image_does_not_fall_through_to_the_name(): void
    {
        $this->assertSame(
            'our-app',
            SidecarEngine::resolve('db', ['image' => 'ghcr.io/acme/our-app:stable']),
            'the author chose that image deliberately; the name must not override it'
        );
    }

    public function test_a_plain_base_image_may_still_fall_through_to_the_name(): void
    {
        $this->assertSame(
            'db',
            SidecarEngine::resolve('db', ['image' => 'alpine:3.20']),
            'a bare base image says nothing about what the service does'
        );
    }

    // --- port agreement ------------------------------------------------

    #[DataProvider('enginePorts')]
    public function test_each_engine_is_addressed_on_the_port_it_is_recognised_by(
        string $engine,
        int $port
    ): void {
        $this->assertSame($port, SidecarEngine::portFor($engine));
        $this->assertSame($engine, SidecarEngine::resolve('svc', ['image' => 'unknown/x:1'], [$port]));
        $this->assertSame($port, SidecarEngine::dialect($engine)['port']);
    }

    /** @return array<string, array{string, int}> */
    public static function enginePorts(): array
    {
        return [
            'postgres' => ['postgres', 5432],
            'mysql' => ['mysql', 3306],
            'redis' => ['redis', 6379],
            'mongo' => ['mongo', 27017],
            'memcached' => ['memcached', 11211],
            'elasticsearch' => ['elasticsearch', 9200],
            'typesense' => ['typesense', 8108],
            'meilisearch' => ['meilisearch', 7700],
            'rabbitmq' => ['rabbitmq', 5672],
        ];
    }

    // --- backing service vs application --------------------------------

    public function test_a_store_we_have_no_settings_for_is_still_a_backing_service(): void
    {
        $siblings = ['web' => ['image' => 'acme/app:1', 'depends_on' => ['s3']], 's3' => ['image' => 'minio/minio:latest']];

        $this->assertTrue(SidecarEngine::isBackingService('s3', $siblings['s3'], [], $siblings));
        $this->assertSame('minio', SidecarEngine::resolve('s3', $siblings['s3']));
    }

    /**
     * Three independent ways to show a service is the application. Each is
     * pinned separately, because a project only ever offers one of them.
     */
    public function test_the_application_is_recognised_by_the_repository_it_came_from(): void
    {
        $siblings = ['web' => ['image' => 'ghcr.io/acme/notes:stable'], 'db' => ['image' => 'postgres:16']];

        $this->assertFalse(
            SidecarEngine::isBackingService('web', $siblings['web'], [], $siblings, 'acme/notes')
        );
        $this->assertTrue(
            SidecarEngine::isBackingService('db', $siblings['db'], [], $siblings, 'acme/notes')
        );
    }

    public function test_the_application_is_recognised_by_what_it_depends_on(): void
    {
        $siblings = [
            'web' => ['image' => 'ghcr.io/acme/notes:stable', 'depends_on' => ['store']],
            'store' => ['image' => 'surrealdb/surrealdb:v2'],
        ];

        $this->assertFalse(SidecarEngine::isBackingService('web', $siblings['web'], [], $siblings));
        $this->assertTrue(SidecarEngine::isBackingService('store', $siblings['store'], [], $siblings));
    }

    public function test_the_application_is_recognised_by_running_twice_under_one_image(): void
    {
        $siblings = [
            'web' => ['image' => 'ghcr.io/acme/notes:stable'],
            'worker' => ['image' => 'ghcr.io/acme/notes:stable'],
            'store' => ['image' => 'questdb/questdb:8'],
        ];

        $this->assertFalse(SidecarEngine::isBackingService('web', $siblings['web'], [], $siblings));
        $this->assertFalse(SidecarEngine::isBackingService('worker', $siblings['worker'], [], $siblings));
        $this->assertTrue(SidecarEngine::isBackingService('store', $siblings['store'], [], $siblings));
    }

    /**
     * The point of the whole redesign: a datastore nobody has catalogued is
     * still a datastore. It used to be dropped, and the project deployed with
     * no database at all.
     */
    public function test_an_unknown_datastore_is_kept_even_though_it_is_on_no_list(): void
    {
        $siblings = [
            'web' => ['image' => 'ghcr.io/acme/notes:stable', 'depends_on' => ['surreal']],
            'surreal' => ['image' => 'surrealdb/surrealdb:v2'],
        ];

        $this->assertTrue(SidecarEngine::isBackingService('surreal', $siblings['surreal'], [], $siblings));
        $this->assertSame(
            'surrealdb',
            SidecarEngine::resolve('surreal', $siblings['surreal']),
            'it names itself; no catalogue needed'
        );
        $this->assertFalse(
            SidecarEngine::isKnownDatastore('surreal', $siblings['surreal']),
            'kept and started, but its dialect is unknown — the two are different questions'
        );
    }

    public function test_environment_is_read_in_the_list_form_as_well(): void
    {
        $service = [
            'image' => 'myorg/fork:1',
            'environment' => ['POSTGRES_USER=appuser', 'POSTGRES_PASSWORD=pw', 'DEBUG'],
        ];

        $this->assertSame('postgres', SidecarEngine::resolve('db', $service));
    }

    // --- what a stack already provides ---------------------------------

    public function test_a_stack_is_recognised_as_already_having_a_database(): void
    {
        $services = ['db' => ['image' => 'mariadb:11'], 'web' => ['image' => 'acme/app:1']];

        $this->assertTrue(SidecarEngine::servicesProvide($services, 'mysql'));
        $this->assertFalse(SidecarEngine::servicesProvide($services, 'postgres'));
    }

    public function test_a_backup_job_does_not_count_as_the_database_it_backs_up(): void
    {
        $services = ['backups' => ['image' => 'databack/mysql-backup:latest']];

        $this->assertFalse(
            SidecarEngine::servicesProvide($services, 'mysql'),
            'counting the backup job as the database skips the one the app needs'
        );
    }

    public function test_percona_and_mariadb_answer_the_same_mysql_question(): void
    {
        foreach (['percona:8', 'mariadb:11', 'mysql:8'] as $image) {
            $this->assertTrue(
                SidecarEngine::servicesProvide(['store' => ['image' => $image]], 'mysql'),
                $image
            );
        }
    }

    // --- derivation without a catalogue ---------------------------------

    /**
     * The point of deriving rather than looking up: an engine that exists in
     * no table still arrives fully configured, because the compose file
     * already says everything needed — the prefix names the engine, the
     * suffixes name the roles, and the port comes from the file.
     */
    public function test_an_engine_in_no_table_is_still_fully_configured(): void
    {
        $yaml = "services:\n"
            . "  web:\n    image: acme/notes:1\n    depends_on: [store]\n"
            . "  store:\n    image: acme/weird-db:3\n    ports: ['7788:7788']\n"
            . "    environment:\n"
            . "      WEIRDDB_USER: u\n      WEIRDDB_PASSWORD: p\n      WEIRDDB_DATABASE: d\n";

        $env = ComposeHarden::extractRuntimeSidecarsFromYaml($yaml, true)['env'];

        $this->assertSame('store', $env['WEIRDDB_HOST']);
        $this->assertSame('7788', $env['WEIRDDB_PORT']);
        $this->assertSame('u', $env['WEIRDDB_USER']);
        $this->assertSame('p', $env['WEIRDDB_PASSWORD']);
        $this->assertSame('d', $env['WEIRDDB_DATABASE']);
    }

    /**
     * A service hands back the variable names its own image chose, whatever
     * they are, so nothing has to enumerate them ahead of time.
     */
    public function test_a_services_own_variables_are_handed_back_under_their_own_names(): void
    {
        $yaml = "services:\n"
            . "  web:\n    image: acme/notes:1\n    depends_on: [search]\n"
            . "  search:\n    image: getmeili/meilisearch:v1\n"
            . "    environment:\n      MEILI_MASTER_KEY: topsecretkey\n      MEILI_ENV: production\n";

        $env = ComposeHarden::extractRuntimeSidecarsFromYaml($yaml, true)['env'];

        $this->assertSame('topsecretkey', $env['MEILI_MASTER_KEY']);
        $this->assertSame('production', $env['MEILI_ENV']);
        $this->assertSame('search', $env['MEILI_HOST']);
    }

    /**
     * The dialect table supplies only what a compose file cannot: the URL
     * scheme an ecosystem answers to, and the driver name a framework knows
     * it by.
     */
    public function test_the_dialect_supplies_only_what_cannot_be_derived(): void
    {
        $postgres = SidecarEngine::dialect('postgres');
        $this->assertSame('postgres', $postgres['scheme']);
        $this->assertSame('pgsql', $postgres['driver'], 'Laravel spells it differently from the engine');

        $mongo = SidecarEngine::dialect('mongo');
        $this->assertSame('mongodb', $mongo['scheme'], 'the scheme is not the engine name');

        $unknown = SidecarEngine::dialect('weird-db');
        $this->assertNull($unknown['scheme']);
        $this->assertNull($unknown['driver']);
        $this->assertSame(0, $unknown['port'], 'nothing invented for an engine we do not know');
    }

    public function test_an_observed_port_beats_the_dialects_default(): void
    {
        $this->assertSame(5433, SidecarEngine::dialect('postgres', 5433)['port']);
    }

    // --- the catalogue is data, not code --------------------------------

    /**
     * No product is named in SidecarEngine or ComposeHarden. Everything those
     * classes know about Postgres, Redis and the rest comes from a data file,
     * which is what lets an operator add an engine without a release.
     */
    public function test_the_logic_names_no_product(): void
    {
        $products = '/postgres|mysql|mariadb|percona|redis|valkey|mongo|memcached'
            . '|typesense|meili|elastic|opensearch|rabbit|cassandra|influx|minio|kafka/i';

        $sources = [
            'SidecarEngine.php' => 'Sidecar/SidecarEngine.php',
            'ComposeHarden.php' => 'Compose/ComposeHarden.php',
        ];

        foreach ($sources as $file => $relative) {
            $path = dirname(__DIR__, 4) . '/app/Lib/Deploy/' . $relative;
            // Without this the test passed for the wrong reason: an unreadable
            // path yielded an empty string, which names no products at all.
            $this->assertFileIsReadable($path);
            $code = '';
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            $this->assertSame(
                0,
                preg_match_all($products, $code),
                "{$file} must not name a product outside its comments"
            );
        }
    }

    public function test_every_catalogue_entry_is_shaped_the_way_the_loader_expects(): void
    {
        foreach (SidecarEngine::dialects() as $engine => $entry) {
            $this->assertIsString($engine);
            $this->assertGreaterThan(0, (int) ($entry['port'] ?? 0), "{$engine} needs a port");

            foreach (['scheme', 'driver', 'default_database', 'mem_limit'] as $optional) {
                if (isset($entry[$optional])) {
                    $this->assertIsString($entry[$optional], "{$engine}.{$optional}");
                }
            }
            foreach (['aliases', 'init_vars'] as $list) {
                if (isset($entry[$list])) {
                    $this->assertIsArray($entry[$list], "{$engine}.{$list}");
                }
            }
        }
    }

    public function test_no_two_engines_claim_the_same_alias(): void
    {
        $seen = [];
        foreach (SidecarEngine::dialects() as $engine => $entry) {
            foreach ((array) ($entry['aliases'] ?? []) as $alias) {
                $this->assertArrayNotHasKey(
                    (string) $alias,
                    $seen,
                    "{$alias} is claimed by both {$engine} and " . ($seen[$alias] ?? '?')
                );
                $seen[(string) $alias] = (string) $engine;
            }
        }
    }

    /**
     * An alias must not collide with an engine's own name either, or the
     * canonical form would depend on which was read first.
     */
    public function test_an_alias_is_never_also_an_engine_name(): void
    {
        $engines = array_keys(SidecarEngine::dialects());
        foreach (SidecarEngine::dialects() as $entry) {
            foreach ((array) ($entry['aliases'] ?? []) as $alias) {
                $this->assertNotContains((string) $alias, $engines);
            }
        }
    }
}
