<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RuntimeSidecars;
use PHPUnit\Framework\TestCase;

/**
 * A compose file that offers the app a choice of SQL database keeps the one
 * the app's own environment names.
 */
class AlternativeDatabasesTest extends TestCase
{
    /**
     * Koillection's docker-compose.dist.yml: its app talks to postgresql, and
     * mysql is there for developers who prefer it.
     */
    private const KOILLECTION = <<<'YAML'
    services:
        koillection:
            build:
                dockerfile: Dockerfile.dev
            environment:
                - DB_DRIVER=pdo_pgsql
                - DB_HOST=postgresql
                - DB_PORT=5432
            depends_on:
                - mysql
                - postgresql
        postgresql:
            image: postgres:18
            environment:
                - POSTGRES_DB=koillection
                - POSTGRES_USER=postgres
                - POSTGRES_PASSWORD=password
        mysql:
            image: mysql:latest
            environment:
                - MYSQL_DATABASE=koillection
                - MYSQL_ROOT_PASSWORD=password
    YAML;

    public function test_of_two_sql_databases_only_the_one_the_app_names_is_kept(): void
    {
        $result = RuntimeSidecars::fromYaml(self::KOILLECTION, true, null, 'github.com/benjaminjonard/koillection');

        $this->assertSame(['postgresql'], array_keys($result['services']));
        $this->assertStringContainsString('@postgresql:5432', $result['env']['DATABASE_URL'] ?? '');
    }

    public function test_a_dropped_database_reports_no_moved_bind(): void
    {
        $yaml = str_replace(
            ["        image: postgres:18\n", "        image: mysql:latest\n"],
            ["        image: postgres:18\n        volumes:\n            - ./data/pg:/var/lib/postgresql/data\n",
                "        image: mysql:latest\n        volumes:\n            - ./data/mysql:/var/lib/mysql\n"],
            self::KOILLECTION
        );
        $this->assertStringContainsString('./data/mysql:/var/lib/mysql', $yaml);
        $result = RuntimeSidecars::fromYaml($yaml, true, null, 'github.com/benjaminjonard/koillection');

        $this->assertSame(['postgresql'], array_keys($result['services']));
        $this->assertSame(['postgresql: ./data/pg:/var/lib/postgresql/data'], $result['replaced_mounts']);
    }

    public function test_two_sql_databases_are_both_kept_when_the_app_names_neither(): void
    {
        $yaml = str_replace('DB_HOST=postgresql', 'DB_HOST=${DB_HOST}', self::KOILLECTION);
        $result = RuntimeSidecars::fromYaml($yaml, true, null, 'github.com/benjaminjonard/koillection');

        $this->assertSame(['postgresql', 'mysql'], array_keys($result['services']));
    }

    public function test_a_url_naming_the_database_is_evidence_too(): void
    {
        $yaml = str_replace('DB_HOST=postgresql', 'DATABASE_URL=mysql://root:password@mysql:3306/koillection', self::KOILLECTION);
        $result = RuntimeSidecars::fromYaml($yaml, true, null, 'github.com/benjaminjonard/koillection');

        $this->assertSame(['mysql'], array_keys($result['services']));
    }

    public function test_a_sql_database_beside_a_cache_is_not_an_alternative(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          app:
            image: ghcr.io/acme/shop
            environment:
              DB_HOST: db
          db:
            image: postgres:16
          redis:
            image: redis:7
        YAML, true, null, 'github.com/acme/shop');

        $this->assertSame(['db', 'redis'], array_keys($result['services']));
    }
}
