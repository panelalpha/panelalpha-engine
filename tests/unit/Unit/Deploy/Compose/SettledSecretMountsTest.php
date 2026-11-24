<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\SettledSecretMounts;
use PHPUnit\Framework\TestCase;

/**
 * The secrets a datastore read through a `*_FILE` variable the engine settled, and where they stay mounted.
 */
class SettledSecretMountsTest extends TestCase
{
    private const DB = [
        'image' => 'postgres:16',
        'environment' => ['POSTGRES_PASSWORD_FILE=/run/secrets/db', 'POSTGRES_USER_FILE=/run/secrets/user'],
        'secrets' => ['db', ['source' => 'user', 'target' => 'user'], 'tls'],
    ];

    public function test_a_variable_the_engine_settled_names_the_secret_it_read(): void
    {
        $settled = ['image' => 'postgres:16', 'environment' => ['POSTGRES_PASSWORD' => 'x', 'POSTGRES_USER_FILE' => '/run/secrets/user']] + self::DB;

        $this->assertSame(['db'], SettledSecretMounts::settledBetween(self::DB, $settled));
    }

    public function test_a_variable_reading_a_path_no_secret_is_mounted_at_names_none(): void
    {
        $before = ['environment' => ['POSTGRES_PASSWORD_FILE' => '/run/secrets/elsewhere'], 'secrets' => ['db']];

        $this->assertSame([], SettledSecretMounts::settledBetween($before, ['environment' => []]));
    }

    /** `/run/secrets/db-old` is another file than `/run/secrets/db`. */
    public function test_a_longer_path_does_not_keep_the_mount(): void
    {
        $db = ['environment' => ['BACKUP_FILE' => '/run/secrets/db-old', 'OTHER' => 'x/run/secrets/db'], 'secrets' => ['db', 'tls']];

        [$services] = SettledSecretMounts::unmounted(['db' => $db], [], ['db' => ['db']], ['db' => ['file' => 'db.txt']], []);

        $this->assertSame(['tls'], $services['db']['secrets']);
    }

    public function test_the_same_path_in_a_command_keeps_the_mount(): void
    {
        $db = ['command' => ['--password-file', '/run/secrets/db'], 'secrets' => ['db']];

        [$services] = SettledSecretMounts::unmounted(['db' => $db], [], ['db' => ['db']], ['db' => ['file' => 'db.txt']], []);

        $this->assertSame(['db'], $services['db']['secrets']);
    }

    /** Swarm creates an external secret; plain Compose has nothing to mount. */
    public function test_an_external_secret_leaves_the_app_in_either_form(): void
    {
        $app = ['db', ['source' => 'db', 'target' => '/etc/db'], 'tls'];

        [, $appSecrets] = SettledSecretMounts::unmounted([], $app, ['db' => ['db']], ['db' => ['external' => true]], []);

        $this->assertSame(['tls'], $appSecrets);
    }

    public function test_a_service_whose_variable_was_not_settled_keeps_a_secret_compose_can_fill(): void
    {
        $worker = ['image' => 'acme/worker:1', 'secrets' => ['db']];

        [$services] = SettledSecretMounts::unmounted(['worker' => $worker], [], ['db' => ['db']], ['db' => ['environment' => 'DB_PASSWORD']], ['DB_PASSWORD' => 'set']);

        $this->assertSame($worker, $services['worker']);
    }
}
