<?php

namespace Tests\Integration\Vault;

use App\Http\Middleware\Authenticate;
use App\Lib\Git\DeployKey;
use App\Lib\Vault\VaultDeployKeys;
use App\Models\SecretVaultEntry;
use App\System;
use Illuminate\Validation\ValidationException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * POST /vault/deploy-keys: an SSH key the vault makes itself, of which only
 * the public half is ever shown, read by the project created with it.
 */
class VaultDeployKeysTest extends TestCase
{
    use InMemoryDatabase;

    private \ArrayObject $scanned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        $this->scanned = new \ArrayObject;
        $this->app->instance(DeployKey::class, new class(new System, $this->scanned) extends DeployKey
        {
            public function __construct(System $system, private readonly \ArrayObject $scanned)
            {
                parent::__construct($system);
            }

            public function generate(string $comment): array
            {
                return [
                    'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n{$comment}\n-----END OPENSSH PRIVATE KEY-----\n",
                    'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl '.$comment,
                    'fingerprint' => 'SHA256:fake',
                    'known_hosts' => [],
                ];
            }

            public function scan(string $host): array
            {
                $this->scanned[] = $host;

                return [$host.' ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKeyHostKeyHostKeyHostKeyHostKeyHost'];
            }
        });
    }

    public function test_a_key_is_generated_and_only_its_public_half_shown(): void
    {
        $response = $this->postJson('/api/vault/deploy-keys', ['purpose' => 'shop repo']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'deploy_key');
        $response->assertJsonPath('data.scope', 'project');
        $response->assertJsonPath('data.status', 'filled');
        $response->assertJsonPath('data.purpose', 'shop repo');
        $this->assertStringStartsWith('ssh-ed25519 ', (string) $response->json('data.public_key'));
        $this->assertSame(['github.com', 'gitlab.com', 'bitbucket.org'], array_column((array) $response->json('data.hosts'), 'host'));
        $this->assertStringNotContainsString('PRIVATE', (string) $response->getContent());

        $ref = (string) $response->json('data.ref');
        $this->getJson("/api/vault/secrets/{$ref}")->assertOk()->assertJsonPath('data.public_key', $response->json('data.public_key'));
        $this->assertStringNotContainsString('PRIVATE', (string) $this->getJson('/api/vault/secrets?type=deploy_key')->getContent());
    }

    public function test_another_git_server_has_its_host_keys_pinned(): void
    {
        $response = $this->postJson('/api/vault/deploy-keys', ['host' => 'Git.Example.com:2222']);

        $response->assertStatus(201);
        $this->assertSame(['[git.example.com]:2222'], $this->scanned->getArrayCopy());
        $this->assertSame('scanned', $response->json('data.hosts.3.pinned'));
        $this->assertSame('[git.example.com]:2222', $response->json('data.hosts.3.host'));
    }

    public function test_a_shipped_host_is_not_scanned(): void
    {
        $this->postJson('/api/vault/deploy-keys', ['host' => 'github.com'])->assertStatus(201);

        $this->assertSame([], $this->scanned->getArrayCopy());
    }

    public function test_bad_input_is_refused(): void
    {
        $this->postJson('/api/vault/deploy-keys', ['scope' => 'everyone'])->assertStatus(422);
        $this->postJson('/api/vault/deploy-keys', ['host' => 'not a host'])->assertStatus(422)->assertJsonPath('problems.0.code', 'host_invalid');
        $this->postJson('/api/vault/deploy-keys', ['scope' => 'global', 'project' => 'shop'])->assertStatus(422);
        $this->postJson('/api/vault/deploy-keys', ['expires_in' => 5])->assertStatus(422);
        $this->assertSame(0, SecretVaultEntry::query()->count());
    }

    public function test_the_key_is_read_back_for_the_project_that_claims_it(): void
    {
        $entry = app(VaultDeployKeys::class)->mint('project', null, null, null, null);

        $key = VaultDeployKeys::resolve('git_deploy_key', $entry->reference(), 'shop');

        $this->assertStringContainsString('PRIVATE KEY', $key['private_key']);
        $this->assertSame('shop', $entry->refresh()->project);
        $this->expectException(ValidationException::class);
        VaultDeployKeys::resolve('git_deploy_key', $entry->reference(), 'other');
    }

    public function test_a_pasted_secret_is_not_a_deploy_key(): void
    {
        $entry = new SecretVaultEntry(['ref_hash' => SecretVaultEntry::hashRef('x'), 'type' => 'git_token', 'link_expires_at' => now()]);
        $entry->setSecret('ghp_xxx');
        $entry->save();

        $this->expectException(ValidationException::class);
        VaultDeployKeys::resolve('git_deploy_key', $entry->reference(), 'shop');
    }
}
