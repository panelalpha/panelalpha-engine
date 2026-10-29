<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\RequestVault;
use App\Lib\Vault\SecretMinter;
use App\Models\SecretVaultEntry;
use Illuminate\Validation\ValidationException;

/** A reference cannot be guessed: it is a random key, and an entry's id never names it. */
class VaultReferenceKeyTest extends VaultTestCase
{
    public function test_a_minted_entry_is_referenced_by_a_random_key(): void
    {
        $entry = $this->mintedGlobal();

        $this->assertMatchesRegularExpression('/^vault:[A-Za-z0-9]{24}$/', $entry->reference());
        $this->assertNotSame($entry->reference(), $this->mintedGlobal()->reference());
    }

    public function test_a_global_entry_is_spent_by_its_reference(): void
    {
        $entry = $this->mintedGlobal();

        $this->assertSame('the-secret', RequestVault::resolve('env_vars', $entry->reference(), 'shop'));
    }

    public function test_a_global_entry_cannot_be_spent_by_its_id(): void
    {
        $entry = $this->mintedGlobal();

        $this->assertRefused('vault:' . $entry->id);
        $this->assertSame(0, $entry->fresh()?->use_count);
    }

    public function test_a_wrong_key_is_refused_as_an_unknown_entry(): void
    {
        $entry = $this->mintedGlobal();

        $this->assertRefused('vault:' . str_repeat('A', 24));
        $this->assertSame(0, $entry->fresh()?->use_count);
    }

    public function test_an_entry_created_outside_the_minter_gets_a_key_too(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_ENV_VARS, 'other-secret');

        $this->assertMatchesRegularExpression('/^vault:[A-Za-z0-9]{24}$/', $entry->reference());
        $this->assertSame('other-secret', RequestVault::resolve('env_vars', $entry->reference(), 'shop'));
    }

    private function mintedGlobal(): SecretVaultEntry
    {
        [$entry] = SecretMinter::mint(SecretVaultEntry::TYPE_ENV_VARS, SecretVaultEntry::SCOPE_GLOBAL);
        $entry->setSecret('the-secret');
        $entry->save();

        return $entry;
    }

    private function assertRefused(string $reference): void
    {
        try {
            RequestVault::resolve('env_vars', $reference, 'shop');
            $this->fail("expected {$reference} to be refused");
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No vault entry', $e->errors()['env_vars'][0]);
        }
    }
}
