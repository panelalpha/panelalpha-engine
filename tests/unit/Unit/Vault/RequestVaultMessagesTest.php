<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\RequestVault;
use App\Models\SecretVaultEntry;
use Illuminate\Validation\ValidationException;

/** Each refusal's exact text, the order the checks run in, and what a read records. */
class RequestVaultMessagesTest extends VaultTestCase
{
    public function test_each_unusable_reference_has_its_own_message(): void
    {
        [$unfilled] = $this->projectEntry();
        [$owned] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_owned', 'blog');
        [$expired] = $this->entry(['secret' => 'ghp_old', 'expires_at' => now()->subMinute()]);
        [$typed] = $this->globalEntry(SecretVaultEntry::TYPE_ENV_VARS, 'v');
        $expiredAt = $expired->expires_at?->toIso8601String();

        $this->assertSame("No vault entry for 'vault:nope'. Use the `vault:<key>` the vault returned when the secret was created.", $this->refusal(' vault:nope '));
        $this->assertSame("Vault entry {$typed->reference()} holds a 'env_vars', not a 'git_token'.", $this->refusal($typed->reference()));
        $this->assertSame("Vault entry {$expired->reference()} expired at {$expiredAt}. Create a new one.", $this->refusal($expired->reference()));
        $this->assertSame(
            "Vault entry {$unfilled->reference()} has no secret pasted yet. Open the paste URL the vault returned when the secret was created.",
            $this->refusal($unfilled->reference())
        );
        $this->assertSame("Vault entry {$owned->reference()} belongs to project 'blog'.", $this->refusal($owned->reference()));
    }

    public function test_the_type_is_checked_before_the_expiry_and_the_expiry_before_the_paste(): void
    {
        [$expiredOtherType] = $this->entry(['type' => SecretVaultEntry::TYPE_ENV_VARS, 'expires_at' => now()->subMinute()]);
        [$expiredUnfilled] = $this->entry(['expires_at' => now()->subMinute()]);

        $this->assertStringContainsString("holds a 'env_vars'", $this->refusal($expiredOtherType->reference()));
        $this->assertStringContainsString('expired at', $this->refusal($expiredUnfilled->reference()));
    }

    public function test_an_undecryptable_secret_is_refused_after_the_entry_was_claimed(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        $entry->forceFill(['secret_encrypted' => 'not-encrypted'])->save();

        $this->assertSame(
            "Vault entry {$entry->reference()} could not be decrypted (APP_KEY rotated?). Create a new one.",
            $this->refusal($entry->reference())
        );
        $entry->refresh();
        $this->assertSame('shop', $entry->project);
        $this->assertSame(0, $entry->use_count);
        $this->assertNull($entry->last_used_at);
    }

    public function test_a_read_counts_the_use_and_stamps_it(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        $this->travelTo(now()->startOfMinute());

        $this->assertSame('ghp_mine', RequestVault::resolve('git_token', ' '.$entry->reference(), 'shop'));
        $this->assertSame('ghp_mine', RequestVault::resolve('token', $entry->reference(), 'shop', SecretVaultEntry::TYPE_GIT_TOKEN));

        $entry->refresh();
        $this->assertSame(2, $entry->use_count);
        $this->assertSame(now()->toIso8601String(), $entry->last_used_at?->toIso8601String());
    }

    private function refusal(string $value, ?string $project = 'shop'): string
    {
        try {
            RequestVault::resolve('git_token', $value, $project);
        } catch (ValidationException $e) {
            $this->assertSame(['git_token'], array_keys($e->errors()));

            return $e->errors()['git_token'][0];
        }

        $this->fail('the reference must be refused');
    }
}
