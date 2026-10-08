<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\CredentialNames;
use PHPUnit\Framework\TestCase;

/**
 * Reading a datastore's credentials from the shape of its variable names.
 *
 * POSTGRES_USER, MYSQL_USER, INFLUXDB_USER and CLICKHOUSE_USER are one
 * pattern - a prefix naming the engine, a suffix naming the role - and
 * reading it that way is what lets an engine nobody catalogued still hand
 * over its username, password and database.
 *
 * The distinction that has to hold is server versus client: a service told
 * DATABASE_HOST is connecting to a database, not being one, and wiring an app
 * up to itself is a stack that starts and then cannot reach its data.
 */
class CredentialNamesTest extends TestCase
{
    public function test_a_catalogued_engines_credentials_are_read(): void
    {
        $found = CredentialNames::extract([
            'POSTGRES_USER' => 'shop',
            'POSTGRES_PASSWORD' => 's3cret',
            'POSTGRES_DB' => 'shop_production',
        ]);

        $this->assertSame('POSTGRES', $found['prefix']);
        $this->assertSame('shop', $found['username']);
        $this->assertSame('s3cret', $found['password']);
        $this->assertSame('shop_production', $found['database']);
    }

    public function test_an_uncatalogued_engine_hands_over_the_same_three_things(): void
    {
        // The point of the class. Nothing here is known to the engine.
        $found = CredentialNames::extract([
            'QUESTDB_USER' => 'app',
            'QUESTDB_PASSWORD' => 'pw',
            'QUESTDB_DATABASE' => 'metrics',
        ]);

        $this->assertSame('QUESTDB', $found['prefix']);
        $this->assertSame('app', $found['username']);
        $this->assertSame('pw', $found['password']);
        $this->assertSame('metrics', $found['database']);
    }

    public function test_the_mongo_and_rabbitmq_spellings_are_read(): void
    {
        $mongo = CredentialNames::extract([
            'MONGO_INITDB_ROOT_USERNAME' => 'root',
            'MONGO_INITDB_ROOT_PASSWORD' => 'pw',
            'MONGO_INITDB_DATABASE' => 'app',
        ]);
        $rabbit = CredentialNames::extract([
            'RABBITMQ_DEFAULT_USER' => 'guest',
            'RABBITMQ_DEFAULT_PASS' => 'guest',
            'RABBITMQ_DEFAULT_VHOST' => '/',
        ]);

        $this->assertSame(['root', 'pw', 'app'], [$mongo['username'], $mongo['password'], $mongo['database']]);
        $this->assertSame(['guest', 'guest', '/'], [$rabbit['username'], $rabbit['password'], $rabbit['database']]);
    }

    public function test_the_longer_suffix_wins(): void
    {
        // _ROOT_PASSWORD has to be matched before _PASSWORD, or the prefix
        // comes out as MYSQL_ROOT and the engine is no longer recognisable.
        $found = CredentialNames::extract(['MYSQL_ROOT_PASSWORD' => 'pw']);

        $this->assertSame('MYSQL', $found['prefix']);
        $this->assertSame('pw', $found['password']);
    }

    public function test_the_first_value_for_a_role_wins(): void
    {
        // MySQL declares both. The user password is the one the app uses.
        $found = CredentialNames::extract([
            'MYSQL_PASSWORD' => 'user-pw',
            'MYSQL_ROOT_PASSWORD' => 'root-pw',
        ]);

        $this->assertSame('user-pw', $found['password']);
    }

    public function test_a_bare_suffix_is_not_a_credential(): void
    {
        // `USER` on its own is the container's run-as user, not a database
        // account. The pattern needs a prefix to mean anything.
        $found = CredentialNames::extract(['USER' => 'www-data', 'PASSWORD' => 'x']);

        $this->assertNull($found['prefix']);
        $this->assertNull($found['username']);
    }

    public function test_a_service_with_no_credentials_yields_nothing(): void
    {
        $found = CredentialNames::extract(['TZ' => 'UTC', 'LANG' => 'C.UTF-8']);

        $this->assertSame(
            ['prefix' => null, 'username' => null, 'password' => null, 'database' => null],
            $found
        );
    }

    public function test_two_roles_under_one_prefix_make_it_a_server(): void
    {
        $this->assertSame(
            ['POSTGRES'],
            CredentialNames::serverPrefixes(['POSTGRES_USER', 'POSTGRES_PASSWORD', 'TZ'])
        );
    }

    public function test_one_role_alone_proves_nothing(): void
    {
        // A single shared name is a coincidence; two is a server declaring
        // how to initialise itself.
        $this->assertSame([], CredentialNames::serverPrefixes(['POSTGRES_PASSWORD', 'TZ']));
    }

    public function test_several_servers_in_one_environment_are_all_found(): void
    {
        $prefixes = CredentialNames::serverPrefixes([
            'POSTGRES_USER', 'POSTGRES_PASSWORD',
            'MYSQL_USER', 'MYSQL_PASSWORD',
        ]);

        sort($prefixes);
        $this->assertSame(['MYSQL', 'POSTGRES'], $prefixes);
    }

    public function test_the_same_role_twice_is_still_one_role(): void
    {
        // _PASSWORD and _PASS name the same thing, as do _USER and _USERNAME.
        // Counting spellings rather than roles let one role satisfy the
        // two-role rule, so a client naming a credential twice was read as
        // the server for an engine called after its own prefix.
        $this->assertSame([], CredentialNames::serverPrefixes(['ACME_PASSWORD', 'ACME_PASS']));
        $this->assertSame([], CredentialNames::serverPrefixes(['ACME_USER', 'ACME_USERNAME']));
    }

    public function test_an_address_marks_the_prefix_as_a_client(): void
    {
        // A server has no need to be told its own address.
        foreach (['_HOST', '_HOSTNAME', '_URL', '_URI', '_DSN', '_ADDR', '_ENDPOINT'] as $marker) {
            $keys = ['DATABASE_USER', 'DATABASE_PASSWORD', 'DATABASE' . $marker];

            $this->assertTrue(CredentialNames::isClientPrefix('DATABASE', $keys), $marker);
        }
    }

    public function test_a_server_prefix_is_not_a_client(): void
    {
        $this->assertFalse(
            CredentialNames::isClientPrefix('POSTGRES', ['POSTGRES_USER', 'POSTGRES_PASSWORD', 'POSTGRES_DB'])
        );
    }

    public function test_another_prefixs_address_does_not_make_this_one_a_client(): void
    {
        // The app service in the same file has REDIS_HOST. That says nothing
        // about whether POSTGRES_* is a server.
        $this->assertFalse(
            CredentialNames::isClientPrefix('POSTGRES', ['POSTGRES_USER', 'POSTGRES_PASSWORD', 'REDIS_HOST'])
        );
    }
}
