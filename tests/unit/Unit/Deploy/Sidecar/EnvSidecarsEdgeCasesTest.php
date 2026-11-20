<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\EnvSidecars;
use PHPUnit\Framework\TestCase;

/**
 * The env-file signals EnvSidecars ignores or ranks below another: a second URL for the same engine,
 * a second engine that wants `db`, a URL it cannot read, and Laravel's DB_* at a remote host.
 */
class EnvSidecarsEdgeCasesTest extends TestCase
{
    public function test_the_first_url_naming_an_engine_wins_over_a_later_one(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://localhost:5432/first',
            'POSTGRES_URL' => 'postgres://localhost:5432/second',
        ]);

        $this->assertSame(['db'], array_keys($result['services']));
        $this->assertSame('first', $result['services']['db']['environment']['POSTGRES_DB']);
        $this->assertSame('postgres://app:app@db:5432/first', $result['env']['DATABASE_URL']);
    }

    public function test_a_second_database_engine_does_not_take_the_db_name(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://localhost:5432/shop',
            'MYSQL_URL' => 'mysql://localhost:3306/legacy',
        ]);

        $this->assertSame(['db'], array_keys($result['services']));
        $this->assertSame('postgres:16', $result['services']['db']['image']);
        $this->assertArrayNotHasKey('MYSQL_DATABASE', $result['services']['db']['environment']);
    }

    public function test_an_unparseable_url_is_skipped_and_the_next_one_still_counts(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://:80',
            'REDIS_URL' => 'redis://localhost:6379',
        ]);

        $this->assertSame(['redis'], array_keys($result['services']));
        $this->assertSame('redis:7', $result['services']['redis']['image']);
    }

    public function test_a_url_with_a_scheme_that_names_no_datastore_is_ignored(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'http://localhost:8080/app',
        ]);

        $this->assertSame(['services' => [], 'volumes' => [], 'env' => []], $result);
    }

    public function test_laravel_pgsql_connection_without_url_gets_postgres(): void
    {
        $result = EnvSidecars::fromVariables([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'localhost',
            'DB_DATABASE' => 'shop',
            'DB_USERNAME' => 'shop',
            'DB_PASSWORD' => 'secret',
        ]);

        $this->assertSame('postgres:16', $result['services']['db']['image']);
        $environment = $result['services']['db']['environment'];
        $this->assertSame('shop', $environment['POSTGRES_DB']);
        $this->assertSame('shop', $environment['POSTGRES_USER']);
        $this->assertSame('secret', $environment['POSTGRES_PASSWORD']);
        $this->assertSame('pgsql', $result['env']['DB_CONNECTION']);
    }

    public function test_laravel_connection_at_a_remote_host_is_left_alone(): void
    {
        $result = EnvSidecars::fromVariables([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db.example.com',
            'DB_DATABASE' => 'shop',
        ]);

        $this->assertSame(['services' => [], 'volumes' => [], 'env' => []], $result);
    }

    public function test_comments_and_free_text_in_an_env_file_are_not_variables(): void
    {
        $files = [
            '/srv/app/.env.example' => "# the database\nnot a variable line\nDATABASE_URL=postgres://localhost/shop\n",
            '/srv/app/.env' => "\n# live\nAPP_KEY=base64:abc\n",
        ];

        $map = EnvSidecars::variableMap('/srv/app/', static fn (string $path): ?string => $files[$path] ?? null);

        $this->assertSame(
            ['DATABASE_URL' => 'postgres://localhost/shop', 'APP_KEY' => 'base64:abc'],
            $map
        );
    }
}
