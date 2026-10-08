<?php

namespace Tests\Unit\Task;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class SqliteTaskTestCase extends TestCase
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

        $path = base_path('database/migrations/2026_09_04_000000_create_tasks_tables.php');
        require_once $path;
        (new \CreateTasksTables())->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('task_logs');
        Schema::dropIfExists('tasks');
        parent::tearDown();
    }
}
