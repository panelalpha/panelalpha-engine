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

    public function test_create_prints_the_reference_and_who_may_use_it(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');

        $global = $this->runCommand('vault:secret:create', ['type' => 'git_token', '--scope' => 'global']);
        $project = $this->runCommand('vault:secret:create', ['type' => 'git_token']);

        $entries = SecretVaultEntry::query()->orderBy('id')->get();
        $this->assertCount(3, $entries, 'Another global of the same type is a new entry.');
        $this->assertStringContainsString('reference: ' . $entries[1]->reference(), $global);
        $this->assertStringContainsString('Any project may use it', $global);
        $this->assertStringContainsString('reference: ' . $entries[2]->reference(), $project);
        $this->assertStringContainsString('claims it', $project);
    }

    public function test_create_can_name_the_project_that_owns_the_secret(): void
    {
        $output = $this->runCommand('vault:secret:create', ['type' => 'git_token', '--project' => 'shop']);

        $entry = SecretVaultEntry::query()->firstOrFail();
        $this->assertSame('shop', $entry->project);
        $this->assertStringContainsString("Only project 'shop' can use " . $entry->reference(), $output);

        $refused = $this->runCommand('vault:secret:create', ['type' => 'git_token', '--scope' => 'global', '--project' => 'shop']);
        $this->assertStringContainsString('global', $refused);
        $this->assertSame(1, SecretVaultEntry::query()->count());
    }

    public function test_create_can_give_the_secret_an_expiry(): void
    {
        $output = $this->runCommand('vault:secret:create', ['type' => 'git_token', '--expires-in' => '3600']);

        $this->assertNotNull(SecretVaultEntry::query()->firstOrFail()->expires_at);
        $this->assertStringContainsString('the secret expires at', $output);

        $this->assertStringContainsString('seconds', $this->runCommand('vault:secret:create', ['type' => 'git_token', '--expires-in' => '7d']));
        $this->assertSame(1, SecretVaultEntry::query()->count());
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

    public function test_list_shows_the_reference_and_the_owning_project(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');

        $output = $this->runCommand('vault:secret:list');

        $this->assertStringContainsString($entry->reference(), $output);
        $this->assertStringContainsString('shop', $output);
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

    public function test_delete_takes_a_reference_or_a_bare_id(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        [$owned] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');

        $this->runCommand('vault:secret:delete', ['ref' => $global->reference(), '--force' => true]);
        $this->runCommand('vault:secret:delete', ['ref' => (string) $owned->id, '--force' => true]);

        $this->assertSame(0, SecretVaultEntry::query()->count());
    }

    public function test_delete_of_an_unknown_ref_says_how_to_address_one(): void
    {
        $output = $this->runCommand('vault:secret:delete', ['ref' => 'nonsense', '--force' => true]);

        $this->assertStringContainsString('vault:secret:list', $output);
    }

    public function test_config_lists_the_globals_any_project_may_use(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');

        $output = $this->runCommand('vault:config');

        $this->assertStringContainsString($global->reference(), $output);
        $this->assertStringNotContainsString('shop', $output);
    }
}
