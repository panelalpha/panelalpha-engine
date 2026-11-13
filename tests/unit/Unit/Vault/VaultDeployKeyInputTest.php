<?php

namespace Tests\Unit\Vault;

use App\Exceptions\ProblemException;
use App\Http\Requests\VaultDeployKeyStoreRequest;
use App\Lib\Git\DeployKey;
use App\Lib\Vault\SecretMinter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * POST /vault/deploy-keys input: the request's own rules, the host format and the shortest life a key may have.
 */
class VaultDeployKeyInputTest extends TestCase
{
    public function test_client_naming_an_unknown_scope_is_refused(): void
    {
        $rules = (new VaultDeployKeyStoreRequest)->rules();

        $this->assertTrue(Validator::make(['scope' => 'project'], $rules)->passes());
        $this->assertTrue(Validator::make(['scope' => 'global'], $rules)->passes());
        $this->assertTrue(Validator::make([], $rules)->passes());
        $this->assertFalse(Validator::make(['scope' => 'everyone'], $rules)->passes());
        $this->assertFalse(Validator::make(['expires_in' => 'soon'], $rules)->passes());
    }

    public function test_client_naming_a_host_with_spaces_or_a_bad_port_is_refused(): void
    {
        foreach (['not a host', 'git.example.com:0', 'git.example.com:70000', 'bad_host!'] as $host) {
            try {
                DeployKey::knownHostsName($host);
                $this->fail("accepted {$host}");
            } catch (ProblemException $e) {
                $this->assertSame('host_invalid', $e->problems[0]['code']);
            }
        }

        $this->assertSame('git.example.com', DeployKey::knownHostsName('Git.Example.com'));
        $this->assertSame('[git.example.com]:2222', DeployKey::knownHostsName('git.example.com:2222'));
    }

    public function test_client_asking_a_key_to_live_under_60_seconds_is_refused(): void
    {
        try {
            SecretMinter::assertExpiry(59);
            $this->fail('59 seconds was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expires_in', $e->errors());
        }

        SecretMinter::assertExpiry(60);
        SecretMinter::assertExpiry(null);
        $this->assertTrue(true);
    }
}
