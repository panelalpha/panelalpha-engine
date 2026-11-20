<?php

namespace Tests\Integration\Console\Vault;

use App\Models\SecretVaultEntry;
use Tests\Integration\Vault\VaultTestCase;

/** vault:config with no globals, vault:secret:create with an over-long purpose, and vault:secret:delete answered no. */
class VaultEdgeCasesTest extends VaultTestCase
{
    public function test_config_without_global_secrets_says_so(): void
    {
        $this->projectEntry();

        $this->artisan('vault:config')->expectsOutput('No global secrets stored.')->assertExitCode(0);
    }

    public function test_create_refuses_a_purpose_over_255_characters(): void
    {
        $this->artisan('vault:secret:create', ['type' => 'git_token', '--purpose' => str_repeat('p', 256)])
            ->expectsOutput('Purpose must be at most 255 characters.')
            ->assertExitCode(1);

        $this->assertSame(0, SecretVaultEntry::query()->count());
    }

    public function test_delete_answered_no_leaves_the_entry(): void
    {
        $this->projectEntry();
        $entry = SecretVaultEntry::query()->sole();

        $this->artisan('vault:secret:delete', ['ref' => $entry->reference()])
            ->expectsConfirmation("Delete entry {$entry->id} ({$entry->type}, {$entry->scope}".($entry->purpose !== null ? ", \"{$entry->purpose}\"" : '').')? The secret cannot be recovered.', 'no')
            ->expectsOutput('Left alone.')
            ->assertExitCode(0);

        $this->assertSame(1, SecretVaultEntry::query()->count());
    }
}
