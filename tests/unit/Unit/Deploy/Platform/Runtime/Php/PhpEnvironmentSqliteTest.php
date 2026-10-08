<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Platform\Runtime\Php\PhpEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * DB_DATABASE for SQLite follows the project's own config/database.php.
 * Heimdall wraps it in database_path(), so the absolute path doubled and it crash-looped.
 */
class PhpEnvironmentSqliteTest extends TestCase
{
    private const STOCK = <<<'PHP'
<?php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'database' => env('DB_DATABASE', 'laravel'),
        ],
    ],
];
PHP;

    private const HEIMDALL = <<<'PHP'
<?php
return [
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => database_path(env('DB_DATABASE', 'app.sqlite')),
            'prefix' => '',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'database' => env('DB_DATABASE', 'forge'),
        ],
    ],
];
PHP;

    /** Heimdall's config/database.php today: a `:memory:` ternary ahead of the wrap. */
    private const HEIMDALL_MEMORY_TERNARY = <<<'PHP'
<?php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),  // Make sure the default connection is set
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            // Use the correct path, but let the special in-memory identifier
            // pass through untouched so tests can run against ':memory:'.
            'database' => env('DB_DATABASE', 'app.sqlite') === ':memory:'
                ? ':memory:'
                : database_path(env('DB_DATABASE', 'app.sqlite')),
            'prefix' => '',
        ],
    ],
];
PHP;

    private static function database(?string $config): string
    {
        return PhpEnvironment::for([], null, true, false, $config)['DB_DATABASE'];
    }

    public function test_stock_laravel_keeps_the_absolute_path(): void
    {
        $this->assertSame('/app/database/database.sqlite', self::database(self::STOCK));
    }

    public function test_a_heimdall_shaped_config_gets_its_own_default_filename(): void
    {
        $this->assertSame('app.sqlite', self::database(self::HEIMDALL));
    }

    public function test_a_wrap_behind_a_memory_ternary_still_counts(): void
    {
        $this->assertSame('app.sqlite', self::database(self::HEIMDALL_MEMORY_TERNARY));
    }

    public function test_a_wrap_under_another_key_does_not_count(): void
    {
        $config = "<?php return ['connections' => ['sqlite' => [\n"
            . "    'database' => env('DB_DATABASE', '/data/app.sqlite'),\n"
            . "    'backup' => database_path(env('DB_DATABASE', 'app.sqlite')),\n]]];";

        $this->assertSame('/app/database/database.sqlite', self::database($config));
    }

    public function test_a_wrap_with_no_default_gets_database_sqlite(): void
    {
        $config = "<?php return ['connections' => [\"sqlite\" => [\n"
            . "    \"database\" => database_path( env( \"DB_DATABASE\" ) ),\n]]];";

        $this->assertSame('database.sqlite', self::database($config));
    }

    public function test_the_account_env_vars_still_win(): void
    {
        $decision = ComposeEnvironment::layer(
            ['env' => PhpEnvironment::for([], null, true, false, self::HEIMDALL)],
            [],
            ['DB_DATABASE' => 'mine.sqlite']
        );

        $this->assertSame('mine.sqlite', $decision['env']['DB_DATABASE']);
    }

    public function test_a_missing_or_unreadable_config_keeps_the_absolute_path(): void
    {
        $this->assertSame('/app/database/database.sqlite', self::database(null));
        $this->assertSame('/app/database/database.sqlite', self::database('<?php return require "x.php";'));
    }

    /** A wrap on another connection says nothing about SQLite. */
    public function test_a_wrap_outside_the_sqlite_block_is_ignored(): void
    {
        $config = "<?php return ['connections' => ['sqlite' => ['database' => env('DB_DATABASE')],"
            . " 'other' => ['database' => database_path(env('DB_DATABASE', 'x.db'))]]];";

        $this->assertSame('/app/database/database.sqlite', self::database($config));
    }
}
