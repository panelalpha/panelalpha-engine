<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Every entry that existed before `ref_key` gets one, and its old `vault:<id>` stops naming it. */
class MigrationRefKeyTest extends TestCase
{
    private object $migration;

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

        Schema::create('secret_vault_entries', function (Blueprint $table) {
            $table->id();
            $table->char('ref_hash', 64)->unique()->index();
            $table->string('type');
            $table->string('scope', 16)->default(SecretVaultEntry::SCOPE_PROJECT);
            $table->timestamps();
        });

        $this->migration = require base_path(
            'database/migrations/2026_10_16_000000_add_ref_key_to_secret_vault_entries_table.php'
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('secret_vault_entries');
        parent::tearDown();
    }

    public function test_existing_entries_get_distinct_keys_and_lose_their_id_reference(): void
    {
        foreach (['a', 'b'] as $fill) {
            DB::table('secret_vault_entries')->insert([
                'ref_hash' => str_repeat($fill, 64),
                'type' => SecretVaultEntry::TYPE_GIT_TOKEN,
                'scope' => SecretVaultEntry::SCOPE_GLOBAL,
            ]);
        }

        $this->migration->up();

        $keys = DB::table('secret_vault_entries')->pluck('ref_key', 'id')->all();
        $this->assertCount(2, array_unique($keys));
        foreach ($keys as $id => $key) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', (string) $key);
            $this->assertSame($id, SecretVaultEntry::findByReference('vault:' . $key)?->id);
            $this->assertNull(SecretVaultEntry::findByReference('vault:' . $id));
        }
    }
}
