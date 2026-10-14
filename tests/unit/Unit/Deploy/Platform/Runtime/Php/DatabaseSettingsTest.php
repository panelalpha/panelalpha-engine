<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\DatabaseSettings;
use PHPUnit\Framework\TestCase;

/**
 * The database block a PHP application is configured with.
 *
 * Every field has a default because the settings arrive from a decision that
 * may say nothing, and a blank host or port produces a connection string that
 * fails at the first query rather than at deploy time - by which point the
 * container is up and reported healthy.
 */
class DatabaseSettingsTest extends TestCase
{
    public function test_the_declared_settings_are_used(): void
    {
        $db = DatabaseSettings::fromArray([
            'connection' => 'mysql',
            'host' => 'db',
            'port' => '3307',
            'database' => 'shop',
            'username' => 'shop',
            'password' => 's3cret',
        ]);

        $this->assertSame('db', $db->host());
        $this->assertSame('3307', $db->port());
        $this->assertSame('shop', $db->database());
        $this->assertSame('shop', $db->username());
        $this->assertSame('s3cret', $db->password());
    }

    public function test_a_setting_that_was_not_given_falls_back(): void
    {
        $db = DatabaseSettings::fromArray(['connection' => 'mysql']);

        $this->assertSame('127.0.0.1', $db->host());
        $this->assertSame('3306', $db->port());
        $this->assertSame('app', $db->database());
        $this->assertSame('root', $db->username());
    }

    public function test_a_blank_setting_falls_back_too(): void
    {
        // An empty DB_HOST in a project's .env means "unset", not "connect to
        // nothing".
        $db = DatabaseSettings::fromArray(['connection' => 'mysql', 'host' => '  ', 'port' => '']);

        $this->assertSame('127.0.0.1', $db->host());
        $this->assertSame('3306', $db->port());
    }

    public function test_an_empty_password_stays_empty(): void
    {
        // Unlike the others: a blank password is a legitimate setting, and
        // substituting one would produce a connection that cannot authenticate.
        $this->assertSame('', DatabaseSettings::fromArray(['connection' => 'mysql'])->password());
    }

    public function test_mariadb_is_configured_as_mysql(): void
    {
        // Laravel has no separate mariadb connection; naming one produces a
        // driver-not-found at boot.
        $db = DatabaseSettings::fromArray(['connection' => 'mariadb']);

        $this->assertSame('mysql', $db->driver());
        $this->assertSame('mariadb', $db->connection());
        $this->assertTrue($db->isMysql());
    }

    public function test_mysql_is_recognised_as_itself(): void
    {
        $db = DatabaseSettings::fromArray(['connection' => 'MySQL']);

        $this->assertTrue($db->isMysql());
        $this->assertSame('mysql', $db->driver());
    }

    public function test_another_engine_is_not_mysql(): void
    {
        $db = DatabaseSettings::fromArray(['connection' => 'pgsql']);

        $this->assertFalse($db->isMysql());
        $this->assertSame('pgsql', $db->driver());
    }

    public function test_a_decision_with_no_connection_names_none(): void
    {
        $db = DatabaseSettings::fromArray([]);

        $this->assertSame('', $db->connection());
        $this->assertFalse($db->isMysql());
    }
}
