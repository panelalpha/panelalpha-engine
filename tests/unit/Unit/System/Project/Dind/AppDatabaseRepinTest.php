<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AppDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * An account moved onto pash-tenants keeps an app pinned to
 * sites-db's address on the old network until the pin is rewritten.
 */
class AppDatabaseRepinTest extends TestCase
{
    private static function compose(): string
    {
        $db = TestNetwork::container2(3);

        return <<<YAML
services:
  app:
    environment:
      DB_HOST: database-users.shared-hosting.palocal
    extra_hosts:
      - 'database-users.shared-hosting.palocal:{$db}'
      - 'other.example:{$db}'
  worker:
    extra_hosts:
      - database-users.shared-hosting.palocal:{$db}
YAML;
    }

    public function test_every_pin_of_the_database_name_moves_and_nothing_else(): void
    {
        config(['env.USERS_DB_HOST' => null]);

        $out = AppDatabase::repinned(self::compose(), TestNetwork::container(2));

        $this->assertSame(2, substr_count($out, 'database-users.shared-hosting.palocal:' . TestNetwork::container(2)));
        $this->assertStringContainsString("'other.example:" . TestNetwork::container2(3) . "'", $out);
        $this->assertStringContainsString('DB_HOST: database-users.shared-hosting.palocal' . "\n", $out);
    }

    public function test_a_file_already_pinned_to_the_address_is_unchanged(): void
    {
        config(['env.USERS_DB_HOST' => null]);
        $once = AppDatabase::repinned(self::compose(), TestNetwork::container(2));

        $this->assertSame($once, AppDatabase::repinned($once, TestNetwork::container(2)));
    }

    public function test_a_database_of_the_operators_own_is_left_alone(): void
    {
        config(['env.USERS_DB_HOST' => 'db.example.test']);
        $yaml = "extra_hosts:\n  - 'db.example.test:" . TestNetwork::public3(10) . "'\n";

        $this->assertSame($yaml, AppDatabase::repinned($yaml, TestNetwork::container(2)));
    }
}
