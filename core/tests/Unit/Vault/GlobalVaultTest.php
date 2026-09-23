<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\GlobalVault;
use App\Models\SecretVaultEntry;

/**
 * The engine's own secrets: one per type, pasted once, read by every project
 * that has none of its own.
 *
 * Two things are load-bearing here and are what these tests are for. A global
 * secret does not expire, so the thing that made the vault a paste *slot* --
 * its clock -- must not reach it. And the sharing switch has to be a real
 * gate: an engine that turns it on has to behave exactly as it did before
 * globals existed.
 */
class GlobalVaultTest extends VaultTestCase
{
    public function test_reads_the_pasted_engine_wide_secret(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        $this->assertSame('ghp_engine', GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
        $this->assertTrue(GlobalVault::has(SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_an_unpasted_global_reads_as_nothing(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN); // minted, never filled

        $this->assertNull(GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
        $this->assertFalse(GlobalVault::has(SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_a_type_with_no_global_reads_as_nothing(): void
    {
        $this->assertNull(GlobalVault::secret(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN));
    }

    public function test_a_request_entry_is_not_an_engine_wide_secret(): void
    {
        // Same type, same pasted secret, but minted for one call: it must not
        // become everybody's Git token by sitting in the same table.
        $this->entry(['secret' => 'ghp_one_call']);

        $this->assertNull(GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_the_secret_outlives_the_paste_link(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $entry->forceFill(['link_expires_at' => now()->subDay()])->save();

        $this->assertTrue($entry->linkExpired(), 'The form must close an hour after it was minted.');
        $this->assertFalse($entry->expired(), 'The secret itself has no clock.');
        $this->assertSame('filled', $entry->status());
        $this->assertSame('ghp_engine', GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_a_read_is_counted(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN);
        GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN);

        // Otherwise a global that every project depends on looks abandoned in
        // vault_secret_list, which is the one place an operator would check
        // before deleting it.
        $this->assertSame(2, $entry->refresh()->use_count);
    }

    public function test_keeping_tokens_per_project_stops_the_fallback(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->keepTokensPerProject();

        $this->assertTrue(GlobalVault::projectScoped());
        $this->assertFalse(GlobalVault::sharingEnabled());
        $this->assertNull(GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
        $this->assertFalse(GlobalVault::has(SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_the_secret_is_still_there_when_sharing_is_turned_back_on(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->keepTokensPerProject();

        GlobalVault::setProjectScoped(false);

        // The switch decides what is reached for, never what is stored, so an
        // operator can try the strict setting and change their mind without
        // having to ask the customer for the token again.
        $this->assertSame('ghp_engine', GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_sharing_is_on_by_default(): void
    {
        $this->assertFalse(GlobalVault::projectScoped());
        $this->assertTrue(GlobalVault::sharingEnabled());
    }

    public function test_an_explicit_read_ignores_the_switch(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->keepTokensPerProject();

        // `vault:global` is the caller naming this secret, not the engine
        // reaching for it on their behalf, and the setting governs the latter.
        $this->assertSame('ghp_engine', GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN, force: true));
    }

    public function test_each_type_has_its_own_engine_wide_secret(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->globalEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf_engine');

        $this->assertSame('ghp_engine', GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
        $this->assertSame('cf_engine', GlobalVault::secret(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN));
    }

    public function test_the_effective_token_inherits_only_when_the_caller_sent_none(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        // What the pre-create remote probe asks: it has to reach the repo with
        // the credential the clone will use, and for a create that sent none
        // that is the engine's. Probing without it would refuse a private
        // repository the deploy would then have read fine.
        $this->assertSame('ghp_engine', GlobalVault::effective(null, SecretVaultEntry::TYPE_GIT_TOKEN));
        $this->assertSame('ghp_mine', GlobalVault::effective('ghp_mine', SecretVaultEntry::TYPE_GIT_TOKEN));
        // '' is the caller saying "no credential" and stays one.
        $this->assertSame('', GlobalVault::effective('', SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_the_effective_token_respects_the_per_project_setting(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->keepTokensPerProject();

        $this->assertNull(GlobalVault::effective(null, SecretVaultEntry::TYPE_GIT_TOKEN));
    }

    public function test_an_undecryptable_secret_reads_as_nothing_rather_than_throwing(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $entry->forceFill(['secret_encrypted' => 'not-a-ciphertext'])->save();

        // An APP_KEY rotation. A fallback has no caller to explain itself to,
        // so the project behaves as it did before there was a global.
        $this->assertNull(GlobalVault::secret(SecretVaultEntry::TYPE_GIT_TOKEN));
    }
}
