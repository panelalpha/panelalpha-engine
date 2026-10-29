<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\MigratorDestinationConfig;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * The destination config carries a token of this migration's own, never the
 * release zip's; the token stays out of step_log.
 */
class WordpressMigrationDestinationConfigTest extends WordpressMigrationTestCase
{
    public function test_destination_body_carries_the_given_token_in_destination_mode(): void
    {
        $token = MigratorDestinationConfig::newToken();
        $path = sys_get_temp_dir() . '/wp-migrator-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, MigratorDestinationConfig::destinationBody($token));

        try {
            /** @var array{token: string, mode: string} $loaded */
            $loaded = include $path;
            $this->assertSame($token, $loaded['token']);
            $this->assertSame('destination', $loaded['mode']);
        } finally {
            @unlink($path);
        }
    }

    public function test_every_new_token_is_64_hex_and_unique(): void
    {
        $first = MigratorDestinationConfig::newToken();
        $second = MigratorDestinationConfig::newToken();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_a_resumed_migration_keeps_its_token_and_a_new_one_gets_its_own(): void
    {
        $kept = MigratorDestinationConfig::newToken();

        $this->assertSame($kept, MigratorDestinationConfig::tokenFor($kept));
        $this->assertNotSame($kept, MigratorDestinationConfig::tokenFor(null));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', MigratorDestinationConfig::tokenFor('shipped-zip-token'));
    }

    public function test_a_token_the_plugin_would_refuse_is_never_written(): void
    {
        $this->expectException(RuntimeException::class);

        MigratorDestinationConfig::destinationBody("abc', phpinfo(), '");
    }

    public function test_store_migrator_token_does_not_appear_in_step_log(): void
    {
        Queue::fake();

        $token = 'destination-config-token-xyz';
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'pw',
        );

        $migration->storeMigratorToken($token);
        $migration->appendStepLog(
            WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN,
            WordpressMigration::STEP_STATUS_COMPLETED,
            'destination plugin installed and activated',
        );
        $migration->refresh();

        $logJson = json_encode($migration->step_log, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $logJson);
        $this->assertSame($token, $migration->migrator_token);
    }
}
