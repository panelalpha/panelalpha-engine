<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Support\Facades\Artisan;

/**
 * The console half: mint, inventory, delete.
 *
 * Worth its own tests rather than trusting the shared minter, because the
 * console is where an operator goes when something has gone wrong, and the
 * two rules that would hurt most to get wrong there are the two these check:
 * a listing must never print a secret, and a stored secret must refuse to be
 * minted over from a shell just as it does over HTTP.
 */
class VaultSecretCommandsTest extends VaultTestCase
{
    /** @param array<string, mixed> $args */
    private function runCommand(string $command, array $args = []): string
    {
        Artisan::call($command, $args);

        return Artisan::output();
    }

    public function test_create_mints_an_entry_and_prints_its_link(): void
    {
        $output = $this->runCommand('vault:secret:create', [
            'type' => 'git_token',
            '--purpose' => 'Deploy key for the shop repo',
        ]);

        $entry = SecretVaultEntry::query()->firstOrFail();
        $this->assertSame('Deploy key for the shop repo', $entry->purpose);
        $this->assertStringContainsString('/vault/', $output);
        $this->assertStringContainsString('Deploy key for the shop repo', $output);
        // The rule that most surprises people later, said at create time.
        $this->assertStringContainsString('cannot be changed', $output);
    }

    public function test_create_refuses_a_bad_type_or_scope(): void
    {
        $this->assertStringContainsString('snake_case', $this->runCommand('vault:secret:create', ['type' => 'Git-Token']));
        $this->assertStringContainsString(
            'Scope must be one of',
            $this->runCommand('vault:secret:create', ['type' => 'git_token', '--scope' => 'everywhere'])
        );
        $this->assertSame(0, SecretVaultEntry::query()->count());
    }

    public function test_create_refuses_to_mint_over_a_stored_global(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        $output = $this->runCommand('vault:secret:create', ['type' => 'git_token', '--scope' => 'global']);

        $this->assertStringContainsString('already set', $output);
        $this->assertSame('ghp_engine', SecretVaultEntry::globalFor('git_token')?->revealSecret());
    }

    public function test_list_shows_the_inventory_and_never_the_secret(): void
    {
        $this->entry(['secret' => 'ghp_verysecret', 'purpose' => 'Shop repo']);

        $output = $this->runCommand('vault:secret:list');

        $this->assertStringNotContainsString('ghp_verysecret', $output);
        $this->assertStringContainsString('Shop repo', $output);
        $this->assertStringContainsString('git_token', $output);
        $this->assertStringContainsString('filled', $output);
    }

    public function test_list_hides_expired_entries_unless_asked(): void
    {
        $this->entry(['secret' => 'gone', 'purpose' => 'Old one', 'expires_at' => now()->subDay()]);

        $this->assertStringNotContainsString('Old one', $this->runCommand('vault:secret:list'));
        $this->assertStringContainsString('Old one', $this->runCommand('vault:secret:list', ['--all' => true]));
    }

    public function test_list_can_filter_by_type(): void
    {
        $this->entry(['type' => 'git_token', 'purpose' => 'Repo one']);
        $this->entry(['type' => 'cloudflare_api_token', 'purpose' => 'Zone one']);

        $output = $this->runCommand('vault:secret:list', ['--type' => 'cloudflare_api_token']);

        $this->assertStringContainsString('Zone one', $output);
        $this->assertStringNotContainsString('Repo one', $output);
    }

    public function test_delete_removes_an_entry_by_the_id_the_listing_shows(): void
    {
        [$entry] = $this->entry(['secret' => 'ghp_pasted']);

        $this->runCommand('vault:secret:delete', ['ref' => (string) $entry->id, '--force' => true]);

        $this->assertNull(SecretVaultEntry::query()->find($entry->id));
    }

    public function test_delete_takes_a_global_by_type(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        $output = $this->runCommand('vault:secret:delete', ['ref' => 'global:git_token', '--force' => true]);

        // The warning matters: this one is in use by every project that has
        // no token of its own.
        $this->assertStringContainsString('Every project without one of its own', $output);
        $this->assertNull(SecretVaultEntry::globalFor('git_token'));
    }

    public function test_delete_of_an_unknown_ref_says_how_to_address_one(): void
    {
        $output = $this->runCommand('vault:secret:delete', ['ref' => 'nonsense', '--force' => true]);

        $this->assertStringContainsString('vault:secret:list', $output);
    }

    public function test_delete_then_create_is_the_way_to_replace_a_global(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_old');

        $this->runCommand('vault:secret:delete', ['ref' => 'global:git_token', '--force' => true]);
        $output = $this->runCommand('vault:secret:create', ['type' => 'git_token', '--scope' => 'global']);

        $this->assertStringContainsString('/vault/', $output);
        $this->assertNull(SecretVaultEntry::globalFor('git_token')?->revealSecret());
    }
}
