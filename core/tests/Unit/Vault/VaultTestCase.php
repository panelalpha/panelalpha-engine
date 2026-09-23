<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\GlobalVault;
use App\Models\SecretVaultEntry;
use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The vault tables on an in-memory sqlite database, mirroring
 * SqliteTaskTestCase: the production database is a real MySQL on a host this
 * suite must not touch, so the migration runs by hand against the connection
 * the test configures.
 */
abstract class VaultTestCase extends TestCase
{
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
        // No forgetInstance('db'): purge/reconnect must act on the same
        // DatabaseManager Eloquent already holds, or the schema lands on a
        // manager the models never see.
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::connection('sqlite')->create('secret_vault_entries', function (Blueprint $table) {
            $table->id();
            $table->char('ref_hash', 64)->unique()->index();
            $table->string('type');
            $table->string('scope', 16)->default(SecretVaultEntry::SCOPE_REQUEST);
            $table->string('purpose', 255)->nullable();
            $table->json('verify_with')->nullable();
            $table->json('verification')->nullable();
            $table->text('secret_encrypted')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('link_expires_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['scope', 'type']);
        });

        // Whether tokens are shared engine-wide is a Setting, and the vault
        // reads it on every fallback. The table is here so that read is a real
        // one -- a runtime override would hide a query that production makes.
        Schema::connection('sqlite')->create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Setting::clearRuntimeSettings();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Schema::connection('sqlite')->dropIfExists('settings');
        Schema::connection('sqlite')->dropIfExists('secret_vault_entries');
        parent::tearDown();
    }

    /**
     * The engine-wide entry for a type, optionally already pasted into.
     *
     * @return array{0: SecretVaultEntry, 1: string}
     */
    protected function globalEntry(string $type = SecretVaultEntry::TYPE_GIT_TOKEN, ?string $secret = null): array
    {
        return $this->entry([
            'type' => $type,
            'scope' => SecretVaultEntry::SCOPE_GLOBAL,
            'expires_at' => null,
            'secret' => $secret,
        ]);
    }

    /** Turn engine-wide sharing off, the way an operator would. */
    protected function keepTokensPerProject(): void
    {
        GlobalVault::setProjectScoped(true);
    }

    /**
     * An entry straight into the table, with the raw ref handed back so a
     * test can build the `vault:<ref>` value the way the API would.
     *
     * @param array<string, mixed> $overrides
     * @return array{0: SecretVaultEntry, 1: string}
     */
    protected function entry(array $overrides = []): array
    {
        $ref = 'ref' . bin2hex(random_bytes(8));
        $params = array_merge([
            'ref_hash' => SecretVaultEntry::hashRef($ref),
            'type' => SecretVaultEntry::TYPE_GIT_TOKEN,
            'link_expires_at' => now()->addSeconds(SecretVaultEntry::TTL_SECONDS),
            'expires_at' => now()->addSeconds(SecretVaultEntry::TTL_SECONDS),
        ], $overrides);
        unset($params['secret']);

        /** @var SecretVaultEntry */
        $entry = SecretVaultEntry::create($params);
        if (!empty($overrides['secret'])) {
            $entry->setSecret((string) $overrides['secret']);
            $entry->save();
        }

        return [$entry, $ref];
    }
}