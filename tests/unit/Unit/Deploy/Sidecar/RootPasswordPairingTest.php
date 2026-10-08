<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\CredentialNames;
use App\Lib\Deploy\Sidecar\SidecarCredentials;
use PHPUnit\Framework\TestCase;

/**
 * The username of one account with the password of another.
 *
 * Each credential was resolved independently, first match in the
 * environment's own order. MySQL is where that breaks: a service declaring
 * `MYSQL_ROOT_PASSWORD`, `MYSQL_USER` and `MYSQL_PASSWORD` describes *two*
 * accounts, and which password came back depended on which line Docker
 * happened to list first.
 *
 * Kanboard's upstream compose lists the root one first. The engine built
 * `mysql://kanboard:secret@db:3306/kanboard` -- the application's username
 * with the administrator's password -- and the site served
 *
 *     Internal Error: SQLSTATE[HY000] [1045] Access denied for user 'kanboard'
 *
 * as HTTP 200, on a deploy reported `success` with `health_healthy: true`.
 * Upstream's own file is internally consistent; the engine broke it while
 * rewriting it.
 */
class RootPasswordPairingTest extends TestCase
{
    /** Kanboard's `docker-compose.mysql.yml`, root password declared first. */
    private const KANBOARD_DB = [
        'MYSQL_ROOT_PASSWORD' => 'secret',
        'MYSQL_USER' => 'kanboard',
        'MYSQL_PASSWORD' => 'kanboard-secret',
        'MYSQL_DATABASE' => 'kanboard',
    ];

    public function test_the_application_account_is_not_given_the_root_password(): void
    {
        $found = CredentialNames::extract(self::KANBOARD_DB);

        $this->assertSame('kanboard', $found['username']);
        $this->assertSame(
            'kanboard-secret',
            $found['password'],
            "the app's username must not be paired with the administrator's password"
        );
    }

    /** Declaration order must not decide which account the app gets. */
    public function test_declaration_order_does_not_change_the_answer(): void
    {
        $reordered = [
            'MYSQL_DATABASE' => 'kanboard',
            'MYSQL_PASSWORD' => 'kanboard-secret',
            'MYSQL_USER' => 'kanboard',
            'MYSQL_ROOT_PASSWORD' => 'secret',
        ];

        $this->assertSame(
            CredentialNames::extract(self::KANBOARD_DB),
            CredentialNames::extract($reordered)
        );
    }

    /** The failure as it reached the browser: the generated URL. */
    public function test_the_generated_connection_url_authenticates(): void
    {
        $env = SidecarCredentials::envForSidecar('db', [
            'image' => 'mariadb:lts',
            'environment' => self::KANBOARD_DB,
        ]);

        $this->assertSame('mysql://kanboard:kanboard-secret@db:3306/kanboard', $env['DATABASE_URL']);
        $this->assertSame('kanboard-secret', $env['DB_PASSWORD']);
        $this->assertSame('kanboard', $env['DB_USERNAME']);
    }

    /**
     * A service that names only an administrator -- Mongo's is the common one
     * -- still gets it. There is no other account to prefer.
     */
    public function test_a_root_only_service_is_unaffected(): void
    {
        $found = CredentialNames::extract([
            'MONGO_INITDB_ROOT_USERNAME' => 'root',
            'MONGO_INITDB_ROOT_PASSWORD' => 'pw',
            'MONGO_INITDB_DATABASE' => 'app',
        ]);

        $this->assertSame(['root', 'pw', 'app'], [$found['username'], $found['password'], $found['database']]);
    }

    /** So is one that names only an application account. */
    public function test_an_app_only_service_is_unaffected(): void
    {
        $found = CredentialNames::extract([
            'POSTGRES_USER' => 'app',
            'POSTGRES_PASSWORD' => 's3cret',
            'POSTGRES_DB' => 'appdb',
        ]);

        $this->assertSame(['app', 's3cret', 'appdb'], [$found['username'], $found['password'], $found['database']]);
    }

    /**
     * A lone `MYSQL_USER` beside a root password is still that user: pairing
     * it beats dropping it, and there is no other username on offer.
     */
    public function test_a_user_without_its_own_password_is_still_named(): void
    {
        $found = CredentialNames::extract([
            'MYSQL_ROOT_PASSWORD' => 'pw',
            'MYSQL_USER' => 'app',
        ]);

        $this->assertSame('app', $found['username']);
        $this->assertSame('pw', $found['password']);
    }

    /** The prefix still comes out as the engine's, not `MYSQL_ROOT`. */
    public function test_the_prefix_survives(): void
    {
        $this->assertSame('MYSQL', CredentialNames::extract(self::KANBOARD_DB)['prefix']);
        $this->assertSame('MYSQL', CredentialNames::extract(['MYSQL_ROOT_PASSWORD' => 'pw'])['prefix']);
    }
}
