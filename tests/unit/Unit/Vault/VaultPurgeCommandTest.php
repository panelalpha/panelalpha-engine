<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Support\Facades\Artisan;

/**
 * The sweeper: an entry whose paste link closed with nothing pasted goes; a
 * filled one never does, and the grace window keeps a just-closed one
 * readable as `abandoned` for an hour.
 */
class VaultPurgeCommandTest extends VaultTestCase
{
    public function test_a_filled_project_entry_is_never_swept(): void
    {
        [$filled] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');
        $filled->forceFill(['link_expires_at' => now()->subYear()])->save();

        $this->assertSame(0, Artisan::call('vault:purge', ['--grace' => 0]));

        $this->assertNotNull(SecretVaultEntry::query()->find($filled->id), 'The project uses it; it has no expiry.');
    }

    public function test_a_secret_past_its_expiry_is_deleted_without_anyone_asking(): void
    {
        [$expired] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_old', 'shop');
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        [$live] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $live->forceFill(['expires_at' => now()->addDay()])->save();

        Artisan::call('vault:purge'); // default grace: 3600s
        $this->assertNotNull(SecretVaultEntry::query()->find($expired->id), 'Kept for the grace window.');

        Artisan::call('vault:purge', ['--grace' => 0]);
        $this->assertNull(SecretVaultEntry::query()->find($expired->id));
        $this->assertNotNull(SecretVaultEntry::query()->find($live->id), 'Not expired yet.');
    }

    public function test_grace_keeps_a_just_abandoned_entry(): void
    {
        [$abandoned] = $this->projectEntry();
        $abandoned->forceFill(['link_expires_at' => now()->subMinute()])->save();

        Artisan::call('vault:purge'); // default grace: 3600s
        $this->assertNotNull(SecretVaultEntry::query()->find($abandoned->id), 'Kept for the grace window.');

        Artisan::call('vault:purge', ['--grace' => 0]);
        $this->assertNull(SecretVaultEntry::query()->find($abandoned->id));
    }

    public function test_an_engine_wide_secret_is_never_swept(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        // Its paste form closed long ago; the secret is the point and it stays.
        $global->forceFill(['link_expires_at' => now()->subYear()])->save();

        Artisan::call('vault:purge', ['--grace' => 0]);

        $this->assertNotNull(
            SecretVaultEntry::query()->find($global->id),
            'Deleting a global on a cron would break every project that names it.'
        );
    }

    public function test_an_abandoned_global_mint_is_swept(): void
    {
        // Minted, form closed, nothing ever pasted.
        [$abandoned] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN);
        $abandoned->forceFill(['link_expires_at' => now()->subDay()])->save();

        Artisan::call('vault:purge', ['--grace' => 0]);

        $this->assertNull(SecretVaultEntry::query()->find($abandoned->id));
    }

    public function test_a_global_awaiting_its_first_paste_is_kept(): void
    {
        [$pending] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN);

        Artisan::call('vault:purge', ['--grace' => 0]);

        $this->assertNotNull(
            SecretVaultEntry::query()->find($pending->id),
            'The form is still open; sweeping it would break the link somebody is about to use.'
        );
    }
}