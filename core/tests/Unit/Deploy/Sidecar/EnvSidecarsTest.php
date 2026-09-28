<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\EnvSidecars;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use PHPUnit\Framework\TestCase;

class EnvSidecarsTest extends TestCase
{
    public function test_localhost_postgres_url_becomes_a_db_sidecar(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://localhost:5432/saas_starter',
            'BETTER_AUTH_SECRET' => 'unused-here',
        ]);

        $this->assertArrayHasKey('db', $result['services']);
        $this->assertSame('postgres:16', $result['services']['db']['image']);
        $this->assertSame('saas_starter', $result['services']['db']['environment']['POSTGRES_DB']);
        $this->assertSame('app', $result['services']['db']['environment']['POSTGRES_USER']);
        $this->assertSame('app', $result['services']['db']['environment']['POSTGRES_PASSWORD']);
        $this->assertSame(
            'postgres://app:app@db:5432/saas_starter',
            $result['env']['DATABASE_URL']
        );
        $this->assertSame('pgsql', $result['env']['DB_CONNECTION']);
        $this->assertSame('db', $result['env']['DB_HOST']);
        $this->assertArrayHasKey('dbdata', $result['volumes']);
    }

    public function test_credentials_in_the_url_are_kept(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgresql://alice:s3cret@127.0.0.1:5432/shop',
        ]);

        $this->assertSame('alice', $result['services']['db']['environment']['POSTGRES_USER']);
        $this->assertSame('s3cret', $result['services']['db']['environment']['POSTGRES_PASSWORD']);
        $this->assertSame(
            'postgres://alice:s3cret@db:5432/shop',
            $result['env']['DATABASE_URL']
        );
    }

    public function test_external_database_host_is_left_alone(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://user:pass@db.neon.tech:5432/app',
        ]);

        $this->assertSame([], $result['services']);
        $this->assertSame([], $result['env']);
    }

    public function test_redis_url_adds_a_redis_sidecar_alongside_postgres(): void
    {
        $result = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://localhost:5432/app',
            'REDIS_URL' => 'redis://localhost:6379/0',
        ]);

        $this->assertSame(['db', 'redis'], array_keys($result['services']));
        $this->assertSame('redis:7', $result['services']['redis']['image']);
        $this->assertSame('redis://redis:6379/0', $result['env']['REDIS_URL']);
    }

    public function test_laravel_db_connection_without_url(): void
    {
        $result = EnvSidecars::fromVariables([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'shop',
            'DB_USERNAME' => 'shop',
            'DB_PASSWORD' => 'secret',
        ]);

        $this->assertArrayHasKey('db', $result['services']);
        $this->assertSame('mariadb:11', $result['services']['db']['image']);
        $this->assertSame('shop', $result['services']['db']['environment']['MYSQL_DATABASE']);
        $this->assertSame('mysql', $result['env']['DB_CONNECTION']);
        $this->assertStringContainsString('@db:3306/shop', $result['env']['DATABASE_URL']);
    }

    public function test_from_project_dir_reads_env_example(): void
    {
        $dir = sys_get_temp_dir() . '/env-sidecars-' . uniqid('', true);
        mkdir($dir);
        try {
            file_put_contents(
                $dir . '/.env.example',
                "DATABASE_URL=postgres://localhost:5432/from_example\n"
            );

            $result = EnvSidecars::fromProjectDir($dir);

            $this->assertSame(
                'postgres://app:app@db:5432/from_example',
                $result['env']['DATABASE_URL']
            );
        } finally {
            @unlink($dir . '/.env.example');
            @rmdir($dir);
        }
    }

    public function test_live_env_overrides_example(): void
    {
        $dir = sys_get_temp_dir() . '/env-sidecars-' . uniqid('', true);
        mkdir($dir);
        try {
            file_put_contents($dir . '/.env.example', "DATABASE_URL=postgres://localhost:5432/example\n");
            file_put_contents($dir . '/.env', "DATABASE_URL=postgres://localhost:5432/live\n");

            $result = EnvSidecars::fromProjectDir($dir);

            $this->assertStringContainsString('/live', $result['env']['DATABASE_URL']);
        } finally {
            @unlink($dir . '/.env');
            @unlink($dir . '/.env.example');
            @rmdir($dir);
        }
    }

    /** The reader the deploy passes is what is read, not the host path (engine#186). */
    public function test_from_project_dir_reads_through_the_given_reader(): void
    {
        $files = [
            '/home/acct/project/.env.example' => "DATABASE_URL=postgres://localhost:5432/example\n",
            '/home/acct/project/.env' => "DATABASE_URL=postgres://localhost:5432/live\n",
        ];

        $result = EnvSidecars::fromProjectDir('/home/acct/project', fn (string $path): ?string => $files[$path] ?? null);

        $this->assertStringContainsString('/live', $result['env']['DATABASE_URL']);
    }

    public function test_a_mysql_url_seeds_the_user_it_names(): void
    {
        $db = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'mysql://shop:s3cret@localhost:3306/shopdb',
        ])['services']['db'];

        $this->assertSame('shopdb', $db['environment']['MYSQL_DATABASE']);
        $this->assertSame('shop', $db['environment']['MYSQL_USER']);
        $this->assertSame('s3cret', $db['environment']['MYSQL_PASSWORD']);
        // The image creates the named user, but only root can grant it
        // anything, so the root password is set too.
        $this->assertSame('s3cret', $db['environment']['MYSQL_ROOT_PASSWORD']);
    }

    public function test_a_mysql_url_naming_root_still_gets_a_usable_user(): void
    {
        // MySQL will not create `root` as a normal user - MYSQL_USER=root
        // makes the container exit on first boot - so the URL's password
        // becomes the root password, and the hardening pass adds an ordinary
        // `app` user beside it rather than leaving the app without one.
        $db = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'mysql://root:hunter2@localhost:3306/appdb',
        ])['services']['db'];

        $this->assertSame('hunter2', $db['environment']['MYSQL_ROOT_PASSWORD']);
        $this->assertSame('app', $db['environment']['MYSQL_USER']);
    }

    public function test_a_datastore_gets_a_volume_and_a_cache_does_not(): void
    {
        // Redis here was invented from a REDIS_URL: a cache the app expects to
        // find, not somewhere the tenant is keeping anything.
        $postgres = EnvSidecars::fromVariables(['DATABASE_URL' => 'postgres://localhost:5432/app']);
        $redis = EnvSidecars::fromVariables(['REDIS_URL' => 'redis://localhost:6379/0']);

        $this->assertContains('dbdata:/var/lib/postgresql/data', $postgres['services']['db']['volumes']);
        $this->assertArrayHasKey('dbdata', $postgres['volumes']);
        $this->assertSame([], $redis['services']['redis']['volumes'] ?? []);
        $this->assertSame([], $redis['volumes']);
    }

    public function test_mongo_seeds_its_root_user_from_the_url(): void
    {
        $mongo = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'mongodb://carol:pw@localhost:27017/records',
        ])['services']['mongo'];

        $this->assertSame('records', $mongo['environment']['MONGO_INITDB_DATABASE']);
        $this->assertSame('carol', $mongo['environment']['MONGO_INITDB_ROOT_USERNAME']);
        $this->assertSame('pw', $mongo['environment']['MONGO_INITDB_ROOT_PASSWORD']);
        $this->assertContains('dbdata:/data/db', $mongo['volumes']);
    }

    public function test_a_url_that_names_no_credentials_gets_workable_ones(): void
    {
        // These images refuse to start without a password, so an absent one is
        // filled rather than passed through empty. It is also why MySQL has no
        // MYSQL_ALLOW_EMPTY_PASSWORD path: nothing can reach it.
        $db = EnvSidecars::fromVariables([
            'DATABASE_URL' => 'postgres://localhost:5432',
        ])['services']['db'];

        $this->assertSame('app', $db['environment']['POSTGRES_USER']);
        $this->assertSame('app', $db['environment']['POSTGRES_PASSWORD']);
        $this->assertSame('app', $db['environment']['POSTGRES_DB']);
    }

    public function test_a_url_without_a_password_gets_the_accounts_own_not_app(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');
        $result = EnvSidecars::fromVariables(['DATABASE_URL' => 'mysql://localhost/foo'], $passwords);
        $db = $result['services']['db']['environment'];

        $this->assertSame($passwords->for('MYSQL_PASSWORD'), $db['MYSQL_PASSWORD']);
        $this->assertSame($passwords->for('MYSQL_ROOT_PASSWORD'), $db['MYSQL_ROOT_PASSWORD']);
        $this->assertNotSame($db['MYSQL_PASSWORD'], $db['MYSQL_ROOT_PASSWORD']);
        // The app is told the same password the database was seeded with.
        $this->assertSame('mysql://app:' . $db['MYSQL_PASSWORD'] . '@db:3306/foo', $result['env']['DATABASE_URL']);
        $this->assertSame($db['MYSQL_PASSWORD'], $result['env']['DB_PASSWORD']);
        $this->assertNotContains('app', [$db['MYSQL_PASSWORD'], $db['MYSQL_ROOT_PASSWORD'], $result['env']['DB_PASSWORD']]);
    }

    public function test_postgres_and_mongo_get_the_accounts_own_password_too(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');

        $pg = EnvSidecars::fromVariables(['DATABASE_URL' => 'postgres://localhost:5432/app'], $passwords);
        $mongo = EnvSidecars::fromVariables(['MONGO_URL' => 'mongodb://localhost/records'], $passwords);

        $this->assertSame($passwords->for('POSTGRES_PASSWORD'), $pg['services']['db']['environment']['POSTGRES_PASSWORD']);
        $this->assertSame(
            $passwords->for('MONGO_INITDB_ROOT_PASSWORD'),
            $mongo['services']['mongo']['environment']['MONGO_INITDB_ROOT_PASSWORD']
        );
    }

    public function test_a_password_the_url_names_is_kept_but_root_gets_its_own(): void
    {
        $db = EnvSidecars::fromVariables(
            ['DATABASE_URL' => 'mysql://shop:s3cret@localhost:3306/shopdb'],
            SidecarPasswords::derived('account-seed')
        )['services']['db']['environment'];

        $this->assertSame('s3cret', $db['MYSQL_PASSWORD']);
        $this->assertNotSame('s3cret', $db['MYSQL_ROOT_PASSWORD']);
    }

    public function test_a_root_url_without_a_password_gets_a_root_password(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');
        $db = EnvSidecars::fromVariables(['DATABASE_URL' => 'mysql://root@localhost/foo'], $passwords)['services']['db'];

        $this->assertSame($passwords->for('MYSQL_ROOT_PASSWORD'), $db['environment']['MYSQL_ROOT_PASSWORD']);
    }
}
