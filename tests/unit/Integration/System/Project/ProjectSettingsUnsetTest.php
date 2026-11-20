<?php

namespace Tests\Integration\System\Project;

use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Settings;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Clearing a project setting forgets only that setting, and the values a setter refuses.
 */
class ProjectSettingsUnsetTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
    }

    public function test_unsetting_registry_logins_and_kept_paths_forgets_only_those(): void
    {
        $user = $this->makeUser('alice', [
            'registry_auth' => 'ghcr.io alice token',
            'persist_paths' => ['/app/storage'],
            'git_branch' => 'main',
        ]);
        $settings = $this->settings($user);

        $settings->unset(' Registry-Auth ');
        $settings->unset('persist-paths');

        $details = $user->fresh()->getDetails();
        $this->assertArrayNotHasKey('registry_auth', $details);
        $this->assertArrayNotHasKey('persist_paths', $details);
        $this->assertSame('main', $details['git_branch']);
        $this->assertFalse($settings->get('registry-auth')['set']);
    }

    public function test_unsetting_the_cloudflare_token_without_tunnels_clears_the_connector_records(): void
    {
        $user = $this->makeUser('alice', [
            'cloudflare_api_token' => 'cf-token-value',
            'cloudflare_account_id' => 'acc-1',
            'cloudflare_tunnel_token' => 'tunnel-token',
            'persist_paths' => ['/app/storage'],
        ]);

        $this->settings($user)->unset('cloudflare-api-token');

        $details = $user->fresh()->getDetails();
        $this->assertArrayNotHasKey('cloudflare_api_token', $details);
        $this->assertArrayNotHasKey('cloudflare_account_id', $details);
        $this->assertArrayNotHasKey('cloudflare_tunnel_token', $details);
        $this->assertSame(['/app/storage'], $details['persist_paths']);
    }

    public function test_unsetting_the_cloudflare_token_with_tunnels_needs_force(): void
    {
        $user = $this->makeUser('alice', ['cloudflare_api_token' => 'cf-token-value']);
        $domain = $this->makeMainDomain($user, 'alice.example.test');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $user->id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'alice.example.test', 'details' => []]);

        try {
            $this->settings($user)->unset('cloudflare-api-token');
            $this->fail('The token went while tunnels use it.');
        } catch (CloudflareException $e) {
            $this->assertStringContainsString("Project 'alice' still has Cloudflare tunnel hostname(s).", $e->getMessage());
        }
        $this->assertSame('cf-token-value', $user->fresh()->getCloudflareApiToken());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function refusedValues(): iterable
    {
        yield 'registry logins without a line' => ['registry-auth', "  \n", "registry-auth needs at least one 'host username token' line."];
        yield 'protected other than on' => ['protected', 'yes', "protected takes only 'on'; unset it to turn protection off."];
        yield 'system other than on' => ['system', 'off', "system takes only 'on'; unset it to make the project an ordinary one again."];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedValues')]
    public function test_a_value_the_setter_does_not_take_is_refused_and_nothing_is_stored(string $key, string $value, string $message): void
    {
        $user = $this->makeUser('alice');

        try {
            $this->settings($user)->set($key, $value);
            $this->fail("{$key} took '{$value}'.");
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertFalse($this->settings($user->fresh())->get($key)['set']);
    }

    private function settings(User $user): Settings
    {
        return new Settings(new Project(new System(), $user));
    }
}
