<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RuntimeSidecars;
use App\System\Project\Dind\RuntimeSidecars as DindRuntimeSidecars;
use PHPUnit\Framework\TestCase;

/**
 * Three or more SQL engines that the app's environment names none of are a
 * test matrix, not a stack (Shlink): none of it is kept.
 */
class DatabaseTestMatrixTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/compose/' . $name);
    }

    public function test_shlinks_dev_compose_keeps_none_of_its_test_matrix(): void
    {
        $result = RuntimeSidecars::fromYaml(self::fixture('shlink-docker-compose.yml'), false, null, 'github.com/shlinkio/shlink');

        $this->assertSame([], $result['services']);
        // SQL Server has no sidecar dialect, but it is one of the four SQL engines dropped.
        $this->assertSame(4, $result['dropped_test_matrix']['engines'] ?? null);
        $this->assertSame(['shlink_db_mysql', 'shlink_db_postgres', 'shlink_db_maria', 'shlink_db_ms'], $result['dropped_test_matrix']['databases'] ?? null);
        foreach (['shlink_db_mysql', 'shlink_db_postgres', 'shlink_db_maria', 'shlink_db_ms', 'shlink_redis', 'shlink_redis_acl', 'shlink_mercure', 'shlink_rabbitmq', 'shlink_matomo'] as $name) {
            $this->assertContains($name, $result['dropped_test_matrix']['services'], $name);
        }
    }

    public function test_the_log_line_counts_the_databases_it_lists_and_lists_the_rest_apart(): void
    {
        $result = RuntimeSidecars::fromYaml(self::fixture('shlink-docker-compose.yml'), false, null, 'github.com/shlinkio/shlink');
        $matrix = $result['dropped_test_matrix'];
        $this->assertNotNull($matrix);

        $line = DindRuntimeSidecars::droppedTestMatrixLine($matrix, 'docker-compose.yml');

        $this->assertStringStartsWith(
            'Dropped a test matrix from docker-compose.yml (4 SQL engines, none configured): '
            . 'shlink_db_mysql, shlink_db_postgres, shlink_db_maria, shlink_db_ms; with them: ',
            $line
        );
        $with = explode(', ', substr($line, strpos($line, '; with them: ') + strlen('; with them: ')));
        foreach (['shlink_redis', 'shlink_redis_acl', 'shlink_mercure', 'shlink_rabbitmq', 'shlink_matomo'] as $name) {
            $this->assertContains($name, $with, $name);
        }
        $this->assertSame([], array_values(array_filter($with, static fn (string $n): bool => str_starts_with($n, 'shlink_db_'))));
    }

    public function test_a_matrix_of_databases_alone_has_no_with_them_part(): void
    {
        $line = DindRuntimeSidecars::droppedTestMatrixLine(
            ['engines' => 3, 'databases' => ['mysql', 'postgres', 'mssql'], 'services' => ['mysql', 'postgres', 'mssql']],
            'compose.yml'
        );

        $this->assertSame('Dropped a test matrix from compose.yml (3 SQL engines, none configured): mysql, postgres, mssql', $line);
    }

    public function test_a_sql_server_image_counts_as_an_sql_engine(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            build: .
            volumes: ['./:/app']
          mysql:
            image: mysql:8
          postgres:
            image: postgres:16
          mariadb:
            image: mariadb:11
          sqlserver:
            image: mcr.microsoft.com/mssql/server:2022-latest
          cache:
            image: redis:7
        YAML;
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/acme/app');

        $this->assertSame(['mysql', 'postgres', 'mariadb', 'sqlserver'], $result['dropped_test_matrix']['databases'] ?? null);
        $this->assertSame(4, $result['dropped_test_matrix']['engines']);
        $this->assertSame(['mysql', 'postgres', 'mariadb', 'sqlserver', 'cache'], $result['dropped_test_matrix']['services']);
    }

    public function test_a_service_the_app_names_survives_the_matrix(): void
    {
        $yaml = str_replace("DEFAULT_DOMAIN: localhost:8000", "DEFAULT_DOMAIN: localhost:8000\n            REDIS_SERVERS: tcp://shlink_redis:6379", self::fixture('shlink-docker-compose.yml'));
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/shlinkio/shlink');

        $this->assertSame(['shlink_redis'], array_keys($result['services']));
    }

    public function test_an_engine_the_app_names_keeps_the_rule_as_it_was(): void
    {
        $yaml = str_replace("DEFAULT_DOMAIN: localhost:8000", "DEFAULT_DOMAIN: localhost:8000\n            DB_HOST: shlink_db_postgres", self::fixture('shlink-docker-compose.yml'));
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/shlinkio/shlink');

        $this->assertNull($result['dropped_test_matrix']);
        $this->assertContains('shlink_db_postgres', array_keys($result['services']));
        $this->assertNotContains('shlink_db_mysql', array_keys($result['services']));
    }

    public function test_two_engines_are_not_a_matrix(): void
    {
        $yaml = <<<'YAML'
        services:
          app:
            build: .
            volumes: ['./:/app']
            environment: [DB_HOST=${DB_HOST}]
          postgresql:
            image: postgres:18
          mysql:
            image: mysql:8
        YAML;
        $result = RuntimeSidecars::fromYaml($yaml, false, null, 'github.com/acme/app');

        $this->assertSame(['postgresql', 'mysql'], array_keys($result['services']));
        $this->assertNull($result['dropped_test_matrix']);
    }

    public function test_linkaces_production_file_is_unchanged(): void
    {
        $result = RuntimeSidecars::fromYaml(self::fixture('linkace-docker-compose.production.yml'), true, null, 'github.com/kovah/linkace');

        $this->assertSame(['db', 'meilisearch', 'redis'], array_keys($result['services']));
        $this->assertNull($result['dropped_test_matrix']);
    }
}
