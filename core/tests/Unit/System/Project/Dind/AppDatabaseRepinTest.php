<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AppDatabase;
use Tests\TestCase;

/**
 * An account moved onto pash-tenants keeps an app pinned to
 * sites-db's address on the old network until the pin is rewritten.
 */
class AppDatabaseRepinTest extends TestCase
{
    private const COMPOSE = <<<'YAML'
services:
  app:
    environment:
      DB_HOST: database-users.shared-hosting.palocal
    extra_hosts:
      - 'database-users.shared-hosting.palocal:172.25.0.3'
      - 'other.example:172.25.0.3'
  worker:
    extra_hosts:
      - database-users.shared-hosting.palocal:172.25.0.3
YAML;

    public function test_every_pin_of_the_database_name_moves_and_nothing_else(): void
    {
        config(['env.USERS_DB_HOST' => null]);

        $out = AppDatabase::repinned(self::COMPOSE, '10.200.0.2');

        $this->assertSame(2, substr_count($out, 'database-users.shared-hosting.palocal:10.200.0.2'));
        $this->assertStringContainsString("'other.example:172.25.0.3'", $out);
        $this->assertStringContainsString('DB_HOST: database-users.shared-hosting.palocal' . "\n", $out);
    }

    public function test_a_file_already_pinned_to_the_address_is_unchanged(): void
    {
        config(['env.USERS_DB_HOST' => null]);
        $once = AppDatabase::repinned(self::COMPOSE, '10.200.0.2');

        $this->assertSame($once, AppDatabase::repinned($once, '10.200.0.2'));
    }

    public function test_a_database_of_the_operators_own_is_left_alone(): void
    {
        config(['env.USERS_DB_HOST' => 'db.example.test']);
        $yaml = "extra_hosts:\n  - 'db.example.test:192.0.2.10'\n";

        $this->assertSame($yaml, AppDatabase::repinned($yaml, '10.200.0.2'));
    }
}
