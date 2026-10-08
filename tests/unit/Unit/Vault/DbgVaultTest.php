<?php
namespace Tests\Unit\Vault;
use App\Models\SecretVaultEntry;
use Illuminate\Support\Facades\Schema;

class DbgVaultTest extends VaultTestCase
{
    public function test_dbg_create(): void
    {
        var_dump(Schema::hasTable('secret_vault_entries'));
        [$entry, $ref] = $this->entry();
        var_dump('created id: ' . $entry->id);
        $this->assertTrue(true);
    }
}