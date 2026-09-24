<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\RequestVault;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `RequestVault::get($field, $project)`: reads the request field; a literal
 * passes through; the entry must be of the field's type; a global entry is
 * returned to anyone; a project entry is assigned to the first project that
 * reads it and refused to every other.
 */
class RequestVaultTest extends VaultTestCase
{
    public function test_a_literal_absent_or_empty_field_passes_through(): void
    {
        $this->requestWith(['git_token' => 'ghp_realtoken']);
        $this->assertSame('ghp_realtoken', RequestVault::get('git_token', 'shop'));

        $this->requestWith([]);
        $this->assertNull(RequestVault::get('git_token', 'shop'));

        // '' is how a caller clears a stored credential.
        $this->requestWith(['git_token' => '']);
        $this->assertSame('', RequestVault::get('git_token', 'shop'));
    }

    public function test_a_global_is_returned_to_any_project_and_outside_one(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $this->requestWith(['git_token' => $entry->reference()]);

        $this->assertSame('ghp_shared', RequestVault::get('git_token', 'shop'));
        $this->assertSame('ghp_shared', RequestVault::get('git_token', 'blog'));
        $this->assertSame('ghp_shared', RequestVault::get('git_token', null));
        $this->assertNull($entry->refresh()->project);
        $this->assertSame(3, $entry->use_count);
    }

    public function test_a_project_entry_is_assigned_on_first_read_and_refused_to_others(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        $this->requestWith(['git_token' => $entry->reference()]);

        $this->assertSame('ghp_mine', RequestVault::get('git_token', 'shop'));
        $this->assertSame('shop', $entry->refresh()->project);
        $this->assertSame('ghp_mine', RequestVault::get('git_token', 'shop'), 'Its own project keeps reading it.');

        $this->assertRefused('git_token', "project 'shop'", fn () => RequestVault::get('git_token', 'blog'));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    public function test_outside_a_project_an_unowned_project_entry_is_used_without_assigning_it(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        $this->requestWith(['git_token' => $entry->reference()]);

        $this->assertSame('ghp_mine', RequestVault::get('git_token', null));
        $this->assertNull($entry->refresh()->project, 'A read with no project does not assign it.');

        // The first read with a project assigns it, as always.
        $this->assertSame('ghp_mine', RequestVault::get('git_token', 'shop'));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    public function test_a_secret_created_for_a_project_is_read_only_by_that_project(): void
    {
        [$entry] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN, 'secret' => 'ghp_shop']);
        $entry->forceFill(['project' => 'shop'])->save();
        $this->requestWith(['git_token' => $entry->reference()]);

        $this->assertRefused('git_token', "project 'shop'", fn () => RequestVault::get('git_token', 'blog'));
        $this->assertRefused('git_token', "project 'shop'", fn () => RequestVault::get('git_token', null));
        $this->assertSame('ghp_shop', RequestVault::get('git_token', 'shop'));
    }

    public function test_outside_a_project_an_owned_project_entry_is_refused(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');
        $this->requestWith(['git_token' => $entry->reference()]);

        $this->assertRefused('git_token', "project 'shop'", fn () => RequestVault::get('git_token', null));
        $this->assertSame(0, $entry->refresh()->use_count);
    }

    public function test_an_entry_of_another_type_is_refused_before_it_is_assigned(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');

        foreach ([$entry, $global] as $e) {
            // Field name is the type by default...
            $this->requestWith(['env_vars' => ['TOKEN' => $e->reference()]]);
            $this->assertRefused('env_vars', "holds a 'git_token', not a 'env_vars'", fn () => RequestVault::get('env_vars', 'shop'));

            // ...or given, where one field carries several kinds (project_setting_set).
            $this->requestWith(['value' => $e->reference()]);
            $this->assertRefused(
                'value',
                "not a 'cloudflare_api_token'",
                fn () => RequestVault::get('value', 'shop', SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN)
            );
        }
        $this->assertNull($entry->refresh()->project, 'A refused read must not assign the entry.');
        $this->assertSame(0, $entry->use_count);
        $this->assertSame(0, $global->refresh()->use_count);
    }

    public function test_an_expired_secret_is_refused_and_not_assigned(): void
    {
        [$entry] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN, 'secret' => 'ghp_old', 'expires_at' => now()->subMinute()]);
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $global->forceFill(['expires_at' => now()->subMinute()])->save();

        foreach ([$entry, $global] as $e) {
            $this->requestWith(['git_token' => $e->reference()]);
            $this->assertRefused('git_token', 'expired', fn () => RequestVault::get('git_token', 'shop'));
        }
        $this->assertNull($entry->refresh()->project);
    }

    public function test_every_unusable_reference_is_a_422_naming_the_field(): void
    {
        [$unfilled] = $this->projectEntry();

        foreach ([
            'vault:999' => 'vault_secret_create',
            'vault:abc' => 'vault_secret_create',
            $unfilled->reference() => 'pasted',
        ] as $value => $says) {
            $this->requestWith(['git_token' => $value]);
            $this->assertRefused('git_token', $says, fn () => RequestVault::get('git_token', 'shop'));
        }
        $this->assertNull($unfilled->refresh()->project, 'An unfilled entry is not assigned.');
    }

    public function test_an_array_field_has_each_value_read(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_ENV_VARS, 'db-pass');
        $this->requestWith(['env_vars' => ['APP_ENV' => 'production', 'DB_PASSWORD' => $entry->reference()]]);

        $this->assertSame(['APP_ENV' => 'production', 'DB_PASSWORD' => 'db-pass'], RequestVault::get('env_vars', 'shop'));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    private function assertRefused(string $field, string $says, callable $call): void
    {
        try {
            $call();
            $this->fail('An unusable reference must be refused, not passed through.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($says, $e->errors()[$field][0]);
        }
    }

    /** @param array<string, mixed> $input */
    private function requestWith(array $input): void
    {
        $this->app->instance('request', Request::create('/api/projects', 'POST', $input));
    }
}
