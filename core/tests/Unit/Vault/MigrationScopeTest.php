<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration itself, against the table as it shipped: the two new columns
 * land, `expires_at` becomes nullable, and every row that already existed
 * keeps the paste window it had.
 *
 * Worth a test of its own because the backfill is the part that cannot be
 * re-run: splitting one clock into two has exactly one chance to carry the
 * old value across, and getting it wrong would close every live paste form
 * on the engine at the moment of the upgrade.
 */
class MigrationScopeTest extends TestCase
{
    private object $migration;
    private object $purposeMigration;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        // The table exactly as the original migration created it.
        Schema::create('secret_vault_entries', function (Blueprint $table) {
            $table->id();
            $table->char('ref_hash', 64)->unique()->index();
            $table->string('type');
            $table->text('secret_encrypted')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        $this->migration = require base_path(
            'database/migrations/2026_09_17_000000_add_scope_to_secret_vault_entries_table.php'
        );
        $this->purposeMigration = require base_path(
            'database/migrations/2026_09_18_000000_add_purpose_to_secret_vault_entries_table.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('secret_vault_entries');
        parent::tearDown();
    }

    public function test_an_existing_row_keeps_its_paste_window_and_becomes_request_scoped(): void
    {
        $expires = now()->addMinutes(30);
        DB::table('secret_vault_entries')->insert([
            'ref_hash' => str_repeat('a', 64),
            'type' => 'git_token',
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration->up();

        $row = DB::table('secret_vault_entries')->first();
        $this->assertSame('request', $row->scope);
        $this->assertSame(
            $expires->format('Y-m-d H:i:s'),
            (string) \Illuminate\Support\Carbon::parse($row->link_expires_at)->format('Y-m-d H:i:s'),
            'A form somebody has open must not close because of the upgrade.'
        );
    }

    public function test_purpose_lands_nullable_on_rows_that_predate_it(): void
    {
        DB::table('secret_vault_entries')->insert([
            'ref_hash' => str_repeat('b', 64),
            'type' => 'git_token',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration->up();
        $this->purposeMigration->up();

        // An entry that existed before purposes did keeps working; it simply
        // has none, which the listing shows as a dash.
        $this->assertTrue(Schema::hasColumn('secret_vault_entries', 'purpose'));
        $this->assertNull(DB::table('secret_vault_entries')->first()->purpose);
    }

    public function test_rolling_back_purpose_leaves_the_rest_intact(): void
    {
        $this->migration->up();
        $this->purposeMigration->up();

        $this->purposeMigration->down();

        $this->assertFalse(Schema::hasColumn('secret_vault_entries', 'purpose'));
        $this->assertTrue(Schema::hasColumn('secret_vault_entries', 'scope'));
    }

    public function test_a_global_entry_can_be_stored_without_an_expiry(): void
    {
        $this->migration->up();

        SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref-global'),
            'type' => 'git_token',
            'scope' => SecretVaultEntry::SCOPE_GLOBAL,
            'link_expires_at' => now()->addHour(),
            'expires_at' => null,
        ]);

        $entry = SecretVaultEntry::globalFor('git_token');
        $this->assertNotNull($entry);
        $this->assertNull($entry->expires_at);
        $this->assertFalse($entry->expired());
    }

    public function test_rolling_back_drops_the_globals_and_restores_the_old_shape(): void
    {
        $this->migration->up();

        SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref-global'),
            'type' => 'git_token',
            'scope' => SecretVaultEntry::SCOPE_GLOBAL,
            'link_expires_at' => now()->addHour(),
            'expires_at' => null,
        ]);
        SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref-request'),
            'type' => 'git_token',
            'link_expires_at' => now()->addHour(),
            'expires_at' => now()->addHour(),
        ]);

        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('secret_vault_entries', 'scope'));
        $this->assertFalse(Schema::hasColumn('secret_vault_entries', 'link_expires_at'));
        // The global had no expiry and the column is NOT NULL again, so it
        // cannot survive the rollback -- it goes rather than blocking it.
        $this->assertSame(1, DB::table('secret_vault_entries')->count());
    }
}
