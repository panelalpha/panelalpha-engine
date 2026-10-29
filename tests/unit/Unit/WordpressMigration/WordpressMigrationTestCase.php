<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\Setting;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\ClearSecretsStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestNetwork;
use Tests\TestCase;
use Throwable;

/**
 * In-memory sqlite + the wordpress_migrations migration files and the IPv4 NAT
 * map the transfer address reads, mirroring VaultTestCase / SqliteTaskTestCase
 * so the suite never touches host MySQL.
 *
 * Sequence tests call advance with a substitute sequence. The last step stays
 * the real clear-secrets step unless a test replaces it. Collaborator tests
 * call steps, the client, and batch helpers directly.
 */
abstract class WordpressMigrationTestCase extends TestCase
{
    protected const SOURCE_TOKEN = '5e7c1b2a3d4f5061728394a5b6c7d8e9f0a1b2c3d4e5f60718293a4b5c6d7e8f';

    protected static function engineIpv4(): string
    {
        return TestNetwork::public(7);
    }

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
        (require base_path('database/migrations/2026_05_28_120000_create_ipv4_nat_maps_table.php'))->up();

        $this->forgetMigratorZips();
        Setting::setRuntimeSettings(['default_ipv4' => self::engineIpv4()]);
        $this->app->instance(EngineAddresses::class, new class(self::engineIpv4()) extends EngineAddresses {
            public function __construct(private readonly string $ipv4) {}

            public function ranges(): array
            {
                return [$this->ipv4];
            }
        });
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        $this->forgetMigratorZips();
        Schema::dropIfExists('wordpress_migrations');
        Schema::dropIfExists('ipv4_nat_maps');
        parent::tearDown();
    }

    private function forgetMigratorZips(): void
    {
        (new \ReflectionProperty(WordpressMigratorZip::class, 'cache'))->setValue(null, []);
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
