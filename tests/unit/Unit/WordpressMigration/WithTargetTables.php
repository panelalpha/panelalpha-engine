<?php

namespace Tests\Unit\WordpressMigration;

use Illuminate\Support\Facades\Schema;

/**
 * The users, domains and tunnels tables a start reads to decide its target,
 * for HTTP tests on the bare wordpress_migrations schema.
 */
trait WithTargetTables
{
    private const TARGET_TABLE_MIGRATIONS = [
        '2014_10_12_000000_create_users_table.php',
        '2022_09_06_160855_create_domains_table.php',
        '2026_09_07_000000_create_tunnels_table.php',
    ];

    protected function createTargetTables(): void
    {
        foreach (self::TARGET_TABLE_MIGRATIONS as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
    }

    protected function dropTargetTables(): void
    {
        Schema::dropIfExists('tunnels');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
    }
}
