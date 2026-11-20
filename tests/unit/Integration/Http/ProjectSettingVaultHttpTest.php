<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\SecretVaultEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * PUT /projects/{username}/settings/cloudflare-api-token with a `vault:<key>`: the secret it names is what reaches
 * Cloudflare and is stored, and a reference that cannot be used is a 422 on `value` with nothing stored.
 */
class ProjectSettingVaultHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const ACCOUNTS = 'api.cloudflare.com/client/v4/accounts*';

    private const TOKEN = 'cf-real-token-value';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeUser('shop', ['template' => 'dind']);
        Http::fake([self::ACCOUNTS => Http::response([
            'success' => true,
            'result' => [['id' => 'acc-1', 'name' => 'Example Account']],
        ])]);
    }

    public function test_a_reference_is_sent_to_cloudflare_and_stored_as_its_secret(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, self::TOKEN);

        $response = $this->putToken($entry->reference());

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.account_id', 'acc-1');
        $this->assertSame(self::TOKEN, $this->storedToken());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
        $entry->refresh();
        $this->assertSame('shop', $entry->project);
        $this->assertSame(1, (int) $entry->use_count);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
    }

    public function test_an_unknown_reference_is_422_on_value_and_stores_nothing(): void
    {
        $reference = 'vault:' . Str::random(24);

        $response = $this->putToken($reference);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['value']);
        $this->assertStringContainsString("No vault entry for '{$reference}'", (string) $response->json('errors.value.0'));
        $this->assertNull($this->storedToken());
        Http::assertNothingSent();
    }

    public function test_a_git_token_entry_is_422_on_value_and_stays_unclaimed(): void
    {
        $entry = $this->entry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_not_cloudflare');

        $response = $this->putToken($entry->reference());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['value']);
        $this->assertNull($this->storedToken());
        Http::assertNothingSent();
        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }

    private function putToken(string $value): TestResponse
    {
        return $this->putJson('/api/projects/shop/settings/cloudflare-api-token', ['value' => $value]);
    }

    private function storedToken(): ?string
    {
        return User::findByUsername('shop')->getCloudflareApiToken();
    }

    private function entry(string $type, string $secret): SecretVaultEntry
    {
        $entry = SecretVaultEntry::create([
            'ref_hash' => SecretVaultEntry::hashRef('ref' . bin2hex(random_bytes(8))),
            'type' => $type,
            'scope' => SecretVaultEntry::SCOPE_PROJECT,
            'link_expires_at' => now()->addSeconds(SecretVaultEntry::TTL_SECONDS),
        ]);
        $entry->setSecret($secret);
        $entry->save();

        return $entry;
    }
}
