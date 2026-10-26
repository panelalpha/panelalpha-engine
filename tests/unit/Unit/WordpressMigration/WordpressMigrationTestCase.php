<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\Setting;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\ClearSecretsStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * In-memory sqlite + the wordpress_migrations migration files, mirroring
 * VaultTestCase / SqliteTaskTestCase so the suite never touches host MySQL.
 *
 * Sequence tests call advance with a substitute sequence. The last step stays
 * the real clear-secrets step unless a test replaces it. Collaborator tests
 * call steps, the client, and batch helpers directly.
 */
abstract class WordpressMigrationTestCase extends TestCase
{
    protected const ENGINE_IPV4 = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        foreach (glob(base_path('database/migrations/*_wordpress_migrations_table.php')) ?: [] as $file) {
            (require $file)->up();
        }

        WordpressMigratorZip::clearCache();
        Setting::setRuntimeSettings(['default_ipv4' => self::ENGINE_IPV4]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        WordpressMigratorZip::clearCache();
        Schema::dropIfExists('wordpress_migrations');
        parent::tearDown();
    }

    /**
     * Production step names, with no-ops in place of every step except clear-secrets.
     *
     * @param array<string, MigrationStep> $replacements
     * @return list<MigrationStep>
     */
    protected function hermeticSequence(array $replacements = []): array
    {
        $steps = [];
        foreach (WordPressMigrationModule::stepNames() as $name) {
            if (isset($replacements[$name])) {
                $steps[] = $replacements[$name];
                continue;
            }
            if ($name === WordpressMigration::STEP_CLEAR_SECRETS) {
                $steps[] = new ClearSecretsStep();
                continue;
            }
            $steps[] = new NoOpStep($name);
        }

        return $steps;
    }

    /**
     * Every step records its name. The clear-secrets step also clears credentials.
     *
     * @param list<string> $executed
     * @param array<string, Throwable> $throws
     * @return list<MigrationStep>
     */
    protected function recordingSequence(array &$executed, array $throws = []): array
    {
        $steps = [];
        foreach (WordPressMigrationModule::stepNames() as $name) {
            $steps[] = new RecordingStep(
                $name,
                $executed,
                $throws[$name] ?? null,
                $name === WordpressMigration::STEP_CLEAR_SECRETS,
            );
        }

        return $steps;
    }

    protected function advance(WordpressMigration $migration, array $sequence): void
    {
        (new WordPressMigrationModule($sequence, new NoFailureCleanup()))->advance($migration);
    }
}
