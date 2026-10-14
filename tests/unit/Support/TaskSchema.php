<?php

namespace Tests\Support;

/**
 * The `tasks` tables for tests that build only the schema they touch.
 *
 * The migrations run in order here, so a new `tasks` column reaches every
 * such test without each of them naming the file again.
 */
final class TaskSchema
{
    public static function migrate(): void
    {
        require_once base_path('database/migrations/2026_09_04_000000_create_tasks_tables.php');
        (new \CreateTasksTables())->up();

        (require base_path('database/migrations/2026_10_09_000000_add_api_token_id_to_tasks_table.php'))->up();
    }
}
