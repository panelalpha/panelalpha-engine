<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

/**
 * A project remembers which of its env vars a `vault:<key>` set, so their value
 * is never revealed; a key set again to a value of the caller's own, or removed,
 * is no longer the vault's.
 */
class UserVaultEnvKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_a_key_sent_as_a_vault_reference_is_remembered(): void
    {
        $user = $this->user();

        $user->setEnvVars(['PLAIN' => 'a', 'TOKEN' => 's3cret'], ['PLAIN' => 'a', 'TOKEN' => 'vault:abc']);

        $this->assertSame(['TOKEN'], $user->getVaultEnvKeys());
        $this->assertSame(['PLAIN' => 'a', 'TOKEN' => 's3cret'], $user->getEnvVars());
    }

    public function test_a_merge_that_does_not_name_the_key_keeps_it_the_vaults(): void
    {
        $user = $this->user();
        $user->setEnvVars(['TOKEN' => 's3cret'], ['TOKEN' => 'vault:abc']);

        $user->setEnvVars(['TOKEN' => 's3cret', 'OTHER' => 'b'], ['OTHER' => 'b']);

        $this->assertSame(['TOKEN'], $user->getVaultEnvKeys());
    }

    public function test_a_key_set_to_a_value_of_the_callers_own_is_no_longer_the_vaults(): void
    {
        $user = $this->user();
        $user->setEnvVars(['TOKEN' => 's3cret'], ['TOKEN' => 'vault:abc']);

        $user->setEnvVars(['TOKEN' => 'mine'], ['TOKEN' => 'mine']);

        $this->assertSame([], $user->getVaultEnvKeys());
    }

    public function test_a_removed_or_replaced_key_is_forgotten(): void
    {
        $user = $this->user();
        $user->setEnvVars(['A' => 'x', 'B' => 'y'], ['A' => 'vault:a', 'B' => 'vault:b']);

        $user->setEnvVars(['B' => 'y'], ['A' => '']);
        $this->assertSame(['B'], $user->getVaultEnvKeys());

        $user->setEnvVars(['C' => 'z'], ['C' => 'z']);
        $this->assertSame([], $user->getVaultEnvKeys(), 'a replace that left B out took it away');
    }

    public function test_clearing_every_key_forgets_them_all(): void
    {
        $user = $this->user();
        $user->setEnvVars(['A' => 'x'], ['A' => 'vault:a']);

        $user->setEnvVars([], null);

        $this->assertSame([], $user->getVaultEnvKeys());
    }

    private function user(): User
    {
        $user = new User();
        $user->username = 'vaultenv';

        return $user;
    }
}
