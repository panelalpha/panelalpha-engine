<?php

namespace Tests\Integration\Console\Vault;

use App\Models\SecretVaultEntry;
use Tests\Integration\Vault\VaultTestCase;

/** `vault:secret:list --scope` narrows the listing, refuses an unknown scope, and says when nothing is left. */
class VaultSecretListFilterTest extends VaultTestCase
{
    public function test_an_empty_vault_says_so(): void
    {
        $this->artisan('vault:secret:list')->expectsOutput('No vault entries.')->assertExitCode(0);
    }

    public function test_an_unknown_scope_is_refused(): void
    {
        $this->artisan('vault:secret:list --scope=everywhere')
            ->expectsOutput('Scope must be one of: '.implode(', ', SecretVaultEntry::SCOPES).'.')
            ->assertExitCode(1);
    }

    public function test_scope_narrows_the_listing(): void
    {
        $this->projectEntry();

        $this->artisan('vault:secret:list --scope=global')->expectsOutput('No vault entries.')->assertExitCode(0);
        $this->artisan('vault:secret:list --scope=project')->doesntExpectOutput('No vault entries.')->assertExitCode(0);
    }
}
