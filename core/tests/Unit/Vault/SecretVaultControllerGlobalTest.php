<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\SecretVaultController;
use App\Lib\Vault\GlobalVault;
use App\Lib\Vault\RequestVault;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Request;

/**
 * Minting at engine scope, and the switch that governs whether projects
 * inherit what was minted.
 *
 * The controller is called directly rather than over the route, so these read
 * the mint rules themselves without a bearer token in the way -- the same
 * reason RequestVaultTest fakes a request instead of posting one.
 */
class SecretVaultControllerGlobalTest extends VaultTestCase
{
    public function test_minting_a_global_stores_no_expiry(): void
    {
        $data = $this->mint(['type' => 'git_token', 'scope' => 'global']);

        $this->assertSame('global', $data['scope']);
        $this->assertNull($data['expires_in'], 'An engine-wide secret does not expire.');
        $this->assertSame(SecretVaultEntry::TTL_SECONDS, $data['url_expires_in'], 'Its paste form still does.');
        $this->assertSame(RequestVault::PREFIX . RequestVault::GLOBAL_REF, $data['ref']);

        $entry = SecretVaultEntry::globalFor('git_token');
        $this->assertNotNull($entry);
        $this->assertNull($entry->expires_at);
        $this->assertNotNull($entry->link_expires_at);
    }

    public function test_request_scope_is_still_the_default(): void
    {
        $data = $this->mint(['type' => 'git_token']);

        $this->assertSame('request', $data['scope']);
        $this->assertSame(SecretVaultEntry::TTL_SECONDS, $data['expires_in']);
        $this->assertNull(SecretVaultEntry::globalFor('git_token'));
    }

    public function test_re_minting_an_unpasted_global_rotates_its_link_in_place(): void
    {
        // Nothing stored yet, so there is nothing to override and a retry is
        // just a retry. (Minting over a *stored* secret is refused --
        // {@see SecretIsFinalTest}.)
        [$entry, $oldRef] = $this->globalEntry('git_token');

        $this->mint(['type' => 'git_token', 'scope' => 'global']);

        // One global per type: the row is reused, not forked.
        $this->assertSame(1, SecretVaultEntry::query()->where('scope', 'global')->count());
        // And the link handed out last time no longer opens the form.
        $this->assertNotSame(SecretVaultEntry::hashRef($oldRef), $entry->refresh()->ref_hash);
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->mint(['type' => 'git_token', 'scope' => 'everywhere']);
    }

    public function test_a_global_is_addressable_by_type_after_its_link_rotated(): void
    {
        $this->globalEntry('git_token');
        $this->mint(['type' => 'git_token', 'scope' => 'global']);

        // `global:<type>` is what the entry *is*, so it survives the rotation
        // that makes the original ref unusable.
        $response = (new SecretVaultController())->show('global:git_token');

        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('pending', $data['status']);
        $this->assertSame('global', $data['scope']);
        $this->assertSame('global:git_token', $data['ref']);
    }

    public function test_config_reports_the_switch_and_what_is_stored(): void
    {
        $this->globalEntry('git_token', 'ghp_engine');
        $this->globalEntry('cloudflare_api_token'); // minted, never pasted

        $data = json_decode((string) (new SecretVaultController())->config()->getContent(), true)['data'];

        $this->assertFalse($data['project_scoped_tokens'], 'Sharing is the default.');
        $this->assertTrue($data['global_secrets_shared']);
        // Only what is actually pasted counts as an engine-wide secret.
        $this->assertSame(['git_token'], $data['global_types']);
    }

    public function test_config_can_turn_sharing_off_and_back_on(): void
    {
        $this->app->instance('request', Request::create('/api/vault/config', 'PUT', ['project_scoped_tokens' => true]));
        $response = (new SecretVaultController())->updateConfig(request());

        $this->assertTrue(json_decode((string) $response->getContent(), true)['data']['project_scoped_tokens']);
        $this->assertTrue(GlobalVault::projectScoped());

        $this->app->instance('request', Request::create('/api/vault/config', 'PUT', ['project_scoped_tokens' => false]));
        (new SecretVaultController())->updateConfig(request());

        $this->assertFalse(GlobalVault::projectScoped());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mint(array $input): array
    {
        $request = Request::create('/api/vault/secrets', 'POST', $input);
        $this->app->instance('request', $request);

        $response = (new SecretVaultController())->store($request);

        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode((string) $response->getContent(), true);

        return $body['data'];
    }
}
