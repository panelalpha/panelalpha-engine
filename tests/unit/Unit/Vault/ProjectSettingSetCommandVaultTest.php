<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `pae project:settings:set` takes a `vault:<key>` for a secret setting the
 * same way the HTTP endpoint does: the secret is stored, the entry is claimed
 * for the project, and a reference that cannot be used stops the command
 * before anything is written.
 */
class ProjectSettingSetCommandVaultTest extends TestCase
{
    use InMemoryDatabase;

    private const GHCR_LOGIN = 'ghcr.io deployer ghp_registry_token_value';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->makeUser('shop');
        $this->makeUser('blog');
    }

    public function test_a_registry_auth_reference_stores_its_secret_and_claims_the_entry(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_REGISTRY_AUTH, self::GHCR_LOGIN);

        [$exit, $output] = $this->runSet('registry-auth', $entry->reference());

        $this->assertSame(0, $exit, $output);
        $this->assertSame(self::GHCR_LOGIN, $this->registryAuthOf('shop'));
        $entry->refresh();
        $this->assertSame('shop', $entry->project);
        $this->assertSame(1, (int) $entry->use_count);
        $this->assertStringNotContainsString('ghp_registry_token_value', $output);
    }

    public function test_a_plain_registry_auth_value_is_stored_unchanged(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_REGISTRY_AUTH, 'ghcr.io other other_token');

        [$exit] = $this->runSet('registry-auth', self::GHCR_LOGIN);

        $this->assertSame(0, $exit);
        $this->assertSame(self::GHCR_LOGIN, $this->registryAuthOf('shop'));
        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }

    public function test_an_unknown_reference_prints_the_vault_message_and_stores_nothing(): void
    {
        $reference = 'vault:' . Str::random(24);

        [$exit, $output] = $this->runSet('registry-auth', $reference);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No vault entry for '{$reference}'", $output);
        $this->assertNull($this->registryAuthOf('shop'));
    }

    public function test_a_wrong_type_entry_is_refused_and_nothing_is_stored(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_GIT_TOKEN, self::GHCR_LOGIN);

        [$exit, $output] = $this->runSet('registry-auth', $entry->reference());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("holds a 'git_token'", $output);
        $this->assertNull($this->registryAuthOf('shop'));
        $this->assertNull($entry->refresh()->project);
    }

    public function test_another_projects_entry_is_refused_and_stays_with_its_owner(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_REGISTRY_AUTH, self::GHCR_LOGIN, 'blog');

        [$exit, $output] = $this->runSet('registry-auth', $entry->reference());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("belongs to project 'blog'", $output);
        $this->assertNull($this->registryAuthOf('shop'));
        $this->assertSame('blog', $entry->refresh()->project);
    }

    /** Resolving claims the entry and counts a use, so a "no" at the prompt must leave both untouched. */
    public function test_a_cancelled_confirmation_leaves_the_reference_unclaimed_and_stores_nothing(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_REGISTRY_AUTH, self::GHCR_LOGIN);

        $this->artisan('project:settings:set', [
            'key' => 'registry-auth',
            'value' => $entry->reference(),
            '--project' => 'shop',
        ])
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertNull($this->registryAuthOf('shop'));
        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }

    /** A non-secret key never reads the vault, so the literal reaches set() and is refused there as a path. */
    public function test_a_non_secret_key_does_not_resolve_a_reference(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_REGISTRY_AUTH, 'the-secret-value');

        [$exit, $output] = $this->runSet('persist-paths', $entry->reference());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("persist-paths: 'vault:", $output);
        $this->assertStringNotContainsString('the-secret-value', $output);
        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }

    /**
     * @return array{int, string}
     */
    private function runSet(string $key, string $value): array
    {
        $exit = Artisan::call('project:settings:set', [
            'key' => $key,
            'value' => $value,
            '--project' => 'shop',
            '--force' => true,
        ]);

        return [$exit, Artisan::output()];
    }

    private function registryAuthOf(string $username): ?string
    {
        return User::findByUsername($username)->getRegistryAuth();
    }

    /** A pasted entry of `$type`, optionally owned by `$project`. */
    private function entry(string $type, string $secret, ?string $project = null): SecretVaultEntry
    {
        $entry = SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref' . bin2hex(random_bytes(8))),
            'type' => $type,
            'scope' => SecretVaultEntry::SCOPE_PROJECT,
            'project' => $project,
            'link_expires_at' => now()->addSeconds(SecretVaultEntry::TTL_SECONDS),
        ]);
        $entry->setSecret($secret);
        $entry->save();

        return $entry;
    }
}
