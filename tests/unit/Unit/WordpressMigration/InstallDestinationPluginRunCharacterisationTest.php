<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallDestinationPluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Pins each refusal of the destination plugin install, in the order it checks them.
 * The install itself runs WP-CLI in the account and is not reached here.
 */
class InstallDestinationPluginRunCharacterisationTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_migration_without_a_target_is_refused(): void
    {
        $this->assertRefused(null, 'Cannot install destination plugin: target_label is missing.');
        $this->assertRefused('', 'Cannot install destination plugin: target_label is missing.');
    }

    public function test_an_unknown_target_domain_is_refused(): void
    {
        $this->assertRefused('ghost.test', "Domain 'ghost.test' not found for destination plugin install.");
    }

    public function test_a_domain_whose_account_is_gone_is_refused(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('domains')->insert(['user_id' => 999, 'domain' => 'orphan.test', 'type' => 'main', 'details' => '{}']);

        $this->assertRefused('orphan.test', "No user for domain 'orphan.test'.");
    }

    public function test_an_account_without_uid_and_gid_is_refused(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'alice.test');

        $this->assertRefused('alice.test', "No UID/GID for project 'alice'.");
    }

    public function test_a_missing_install_zip_is_refused_before_anything_is_staged(): void
    {
        $this->makeMainDomain($this->makeUser('alice', ['UID' => 1001, 'GID' => 1001]), 'alice.test');
        $zip = new class extends WordpressMigratorZip {
            /** @var list<int> */
            public array $asked = [];

            public function installZipFor(int $migrationId, ?string $releaseUrl = null): string
            {
                $this->asked[] = $migrationId;

                return '/nonexistent/wordpress-migrator-install.zip';
            }
        };

        try {
            (new InstallDestinationPluginStep($zip))->run($this->migration('alice.test'));
            $this->fail('a missing zip was installed');
        } catch (RuntimeException $e) {
            $this->assertSame('Migrator install zip is missing after download/repack.', $e->getMessage());
        }
        $this->assertSame([7], $zip->asked);
    }

    private function assertRefused(?string $label, string $message): void
    {
        try {
            (new InstallDestinationPluginStep(new WordpressMigratorZip))->run($this->migration($label));
            $this->fail('the install was not refused');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function migration(?string $label): WordpressMigration
    {
        $migration = new WordpressMigration;
        $migration->forceFill(['id' => 7, 'target_label' => $label, 'source_url' => 'https://old.example.test/blog']);

        return $migration;
    }
}
