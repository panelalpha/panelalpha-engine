<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Fresh-install-only migrations (users, domains, settings, backup_containers,
// sftp_accounts) run against a real sqlite connection, so NOCASE actually applies.
abstract class SqliteCollationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        foreach ($this->migrationFiles() as $file) {
            $migration = require base_path($file);
            $migration->up();
        }
    }

    /** @return list<string> */
    protected function migrationFiles(): array
    {
        return [
            'database/migrations/2014_10_12_000000_create_users_table.php',
            'database/migrations/2022_09_06_160855_create_domains_table.php',
            'database/migrations/2026_09_04_120000_create_backup_containers_table.php',
            'database/migrations/2025_09_08_140151_create_sftp_accounts_table.php',
        ];
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sftp_accounts');
        Schema::dropIfExists('backup_containers');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
        parent::tearDown();
    }
}
