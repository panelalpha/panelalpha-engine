<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\RequestVault;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * How the engine's own secrets reach a request: `vault:global` asked for by
 * name, and {@see RequestVault::getOrGlobal()} for a value that is used and
 * dropped.
 *
 * The line these tests hold is that plain {@see RequestVault::get()} did not
 * change. It is what the *storing* call sites use, and a stored credential
 * must stay absent so the project keeps inheriting -- if `get()` quietly
 * started returning the engine's token, every project created would freeze a
 * copy of it and rotating the global would stop reaching anything.
 */
class RequestVaultGlobalTest extends VaultTestCase
{
    public function test_get_does_not_reach_for_the_engine_wide_secret(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->requestWith([]);

        $this->assertNull(
            RequestVault::get('git_token'),
            'A stored field must stay absent and inherit at read time, not copy the global in.'
        );
    }

    public function test_get_or_global_fills_in_an_absent_field(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->requestWith([]);

        $this->assertSame('ghp_engine', RequestVault::getOrGlobal('git_token'));
    }

    public function test_get_or_global_prefers_what_the_caller_sent(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->requestWith(['git_token' => 'ghp_mine']);

        $this->assertSame('ghp_mine', RequestVault::getOrGlobal('git_token'));
    }

    public function test_get_or_global_leaves_an_empty_string_alone(): void
    {
        // '' clears a credential. If it fell back it would be impossible to
        // say "no token" on an engine that has a global one.
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->requestWith(['git_token' => '']);

        $this->assertSame('', RequestVault::getOrGlobal('git_token'));
    }

    public function test_get_or_global_respects_the_per_project_setting(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->keepTokensPerProject();
        $this->requestWith([]);

        $this->assertNull(RequestVault::getOrGlobal('git_token'));
    }

    public function test_vault_global_resolves_to_the_engine_wide_secret(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->requestWith(['git_token' => RequestVault::PREFIX . RequestVault::GLOBAL_REF]);

        $this->assertSame('ghp_engine', RequestVault::get('git_token'));
        $this->assertSame(1, $entry->refresh()->use_count);
    }

    public function test_vault_global_is_honoured_even_when_tokens_are_per_project(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->keepTokensPerProject();
        $this->requestWith(['git_token' => RequestVault::PREFIX . RequestVault::GLOBAL_REF]);

        // The setting governs what the engine reaches for on a caller's
        // behalf, not what a caller may ask for outright.
        $this->assertSame('ghp_engine', RequestVault::get('git_token'));
    }

    public function test_vault_global_takes_its_type_from_the_field(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');
        $this->globalEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf_engine');
        $this->requestWith(['value' => RequestVault::PREFIX . RequestVault::GLOBAL_REF]);

        // As project_setting_set calls it: one field, the type named by the
        // setting key. There is no ref to aim at the wrong secret with.
        $this->assertSame('cf_engine', RequestVault::get('value', SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN));
    }

    public function test_vault_global_with_no_such_secret_is_a_422(): void
    {
        $this->requestWith(['git_token' => RequestVault::PREFIX . RequestVault::GLOBAL_REF]);

        try {
            RequestVault::get('git_token');
            $this->fail('Asking for a global that does not exist must not pass through as null.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('git_token', $e->errors());
            $this->assertStringContainsString('scope: global', $e->errors()['git_token'][0]);
        }
    }

    public function test_vault_global_before_the_paste_is_a_422(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN); // minted, never filled
        $this->requestWith(['git_token' => RequestVault::PREFIX . RequestVault::GLOBAL_REF]);

        try {
            RequestVault::get('git_token');
            $this->fail('An unpasted global must fail, not resolve to null.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pasted', $e->errors()['git_token'][0]);
        }
    }

    public function test_a_literal_global_ref_is_not_confused_with_a_minted_one(): void
    {
        // An entry whose raw ref happened to be the word: refs are 48 random
        // characters, so this cannot occur, but the branch order is what
        // decides it and that deserves to be pinned.
        [, $ref] = $this->entry(['secret' => 'ghp_pasted']);
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        $this->requestWith(['git_token' => RequestVault::PREFIX . $ref]);
        $this->assertSame('ghp_pasted', RequestVault::get('git_token'));

        $this->requestWith(['git_token' => RequestVault::PREFIX . RequestVault::GLOBAL_REF]);
        $this->assertSame('ghp_engine', RequestVault::get('git_token'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requestWith(array $input): void
    {
        $this->app->instance('request', Request::create('/api/projects', 'POST', $input));
    }
}
