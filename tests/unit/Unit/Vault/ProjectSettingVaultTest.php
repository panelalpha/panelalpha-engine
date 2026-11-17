<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use App\System\Project\Settings;
use Illuminate\Validation\ValidationException;

/**
 * `project_setting_set` takes a credential, so a secret setting accepts a
 * `vault:<key>`: the controller reads it for this project and stores the
 * secret, exactly as if the caller had sent it.
 */
class ProjectSettingVaultTest extends VaultTestCase
{
    public function test_a_cloudflare_token_is_a_secret_setting(): void
    {
        $this->assertTrue(Settings::isSecret('cloudflare-api-token'));
    }

    public function test_a_reference_is_stored_as_its_secret_and_assigned_to_the_project(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-real-token-value');

        $this->assertSame('cf-real-token-value', Settings::resolveValue('shop', 'cloudflare-api-token', $entry->reference()));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    public function test_a_literal_passes_through(): void
    {
        $this->assertSame('a-literal-token', Settings::resolveValue('shop', 'cloudflare-api-token', 'a-literal-token'));
    }

    public function test_a_git_token_entry_is_refused_as_a_cloudflare_token(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_not_cloudflare');

        $this->expectException(ValidationException::class);
        Settings::resolveValue('shop', 'cloudflare-api-token', $entry->reference());
    }

    public function test_another_projects_entry_is_refused(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-theirs', 'blog');

        $this->expectException(ValidationException::class);
        Settings::resolveValue('shop', 'cloudflare-api-token', $entry->reference());
    }

    public function test_a_non_secret_key_keeps_a_reference_as_a_literal_and_leaves_the_entry_unclaimed(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-not-for-settings');

        $this->assertSame($entry->reference(), Settings::resolveValue('shop', 'persist-paths', $entry->reference()));

        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }
}
