<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\User\ProjectSettingController;
use App\Models\SecretVaultEntry;
use App\System\Project\Settings;
use Illuminate\Validation\ValidationException;

/**
 * `project_setting_set` takes a credential, so a secret setting accepts a
 * `vault:<id>`: the controller reads it for this project and stores the
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

        $this->assertSame('cf-real-token-value', $this->valueFor('shop', 'cloudflare-api-token', $entry->reference()));
        $this->assertSame('shop', $entry->refresh()->project);
    }

    public function test_a_literal_passes_through(): void
    {
        $this->assertSame('a-literal-token', $this->valueFor('shop', 'cloudflare-api-token', 'a-literal-token'));
    }

    public function test_a_git_token_entry_is_refused_as_a_cloudflare_token(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_not_cloudflare');

        $this->expectException(ValidationException::class);
        $this->valueFor('shop', 'cloudflare-api-token', $entry->reference());
    }

    public function test_another_projects_entry_is_refused(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-theirs', 'blog');

        $this->expectException(ValidationException::class);
        $this->valueFor('shop', 'cloudflare-api-token', $entry->reference());
    }

    private function valueFor(string $username, string $key, string $value): string
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'PUT', ['value' => $value]));
        $method = new \ReflectionMethod(ProjectSettingController::class, 'valueFor');

        return $method->invoke(null, $username, $key, $value);
    }
}
