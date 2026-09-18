<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;

/**
 * The paste page for an engine-wide secret, through the real route.
 *
 * Two things separate it from a request-scope page and both are visible to
 * the person pasting: it says the secret is for the whole server, and its
 * form closes an hour after the mint even though the secret it saves has no
 * expiry at all.
 */
class GlobalVaultFormTest extends VaultTestCase
{
    public function test_the_page_says_the_secret_is_for_the_whole_server(): void
    {
        [, $ref] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN);

        $response = $this->get('/vault/' . $ref);

        $response->assertOk();
        $response->assertSee('name="secret"', false);
        // Somebody pasting a personal token deserves to know how far it goes
        // before they paste it, not after.
        $response->assertSee('Saved for this whole server', false);
    }

    public function test_a_request_scope_page_makes_no_such_claim(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);

        $this->get('/vault/' . $ref)->assertDontSee('Saved for this whole server', false);
    }

    public function test_pasting_stores_the_engine_wide_secret(): void
    {
        [$entry, $ref] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN);

        $this->post('/vault/' . $ref, ['secret' => 'ghp_pasted_once'])->assertOk();

        $entry->refresh();
        $this->assertSame('ghp_pasted_once', $entry->revealSecret());
        $this->assertNull($entry->expires_at, 'An engine-wide secret does not expire.');
    }

    public function test_the_form_closes_an_hour_after_the_mint(): void
    {
        // Pending, because a secret that was pasted seals the entry and that
        // answer comes first. This is the other way a link dies: nobody ever
        // used it, and an hour later it is no longer a way in.
        [$entry, $ref] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN);
        $entry->forceFill(['link_expires_at' => now()->subMinute()])->save();

        $this->get('/vault/' . $ref)->assertSee('This link expired');
        $this->post('/vault/' . $ref, ['secret' => 'ghp_too_late'])->assertSee('This link expired');

        $this->assertNull($entry->refresh()->revealSecret(), 'An expired link must not be a way in.');
    }

    public function test_a_stored_global_cannot_be_overwritten_through_its_link(): void
    {
        [$entry, $ref] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_engine');

        // The link is still live; the secret is what is closed.
        $this->post('/vault/' . $ref, ['secret' => 'ghp_replaced'])->assertSee('This secret is already set');

        $this->assertSame('ghp_engine', $entry->refresh()->revealSecret());
    }
}
