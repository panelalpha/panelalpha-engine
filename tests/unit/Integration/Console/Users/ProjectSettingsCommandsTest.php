<?php

namespace Tests\Integration\Console\Users;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** project:settings:get, :list, :set and :unset, and the deprecated project:set-cloudflare-api-token, against the stored project details. */
class ProjectSettingsCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        Http::preventStrayRequests();
        $this->makeUser('alice', ['template' => 'dind', 'registry_auth' => 'ghcr.io alice ghp_0123456789abcdef', 'persist_paths' => ['uploads']]);
        $this->makeUser('bob');
    }

    public function test_get_prints_a_value_and_redacts_a_secret(): void
    {
        $this->artisan('project:settings:get persist-paths --project=alice')
            ->expectsOutput('uploads')
            ->assertExitCode(0);
        $this->artisan('project:settings:get REGISTRY-AUTH --project=alice')
            ->expectsOutputToContain('ghcr')
            ->doesntExpectOutputToContain('ghp_0123456789abcdef')
            ->assertExitCode(0);
    }

    public function test_get_refuses_and_reports_an_unset_value(): void
    {
        $this->artisan('project:settings:get protected')->expectsOutput('--project is required.')->assertExitCode(1);
        $this->artisan('project:settings:get colour --project=alice')
            ->expectsOutputToContain("Unknown project setting 'colour'.")
            ->assertExitCode(1);
        $this->artisan('project:settings:get protected --project=nobody')->expectsOutput("Project 'nobody' not found.")->assertExitCode(1);
        $this->artisan('project:settings:get protected --project=bob')->expectsOutput("Setting 'protected' is not set.")->assertExitCode(1);
    }

    public function test_list_prints_every_setting_with_secrets_redacted(): void
    {
        $this->artisan('project:settings:list --project=alice')
            ->expectsOutputToContain('cloudflare-api-token')
            ->expectsOutputToContain('uploads')
            ->doesntExpectOutputToContain('ghp_0123456789abcdef')
            ->assertExitCode(0);
        $this->artisan('project:settings:list')->expectsOutput('--project is required.')->assertExitCode(1);
        $this->artisan('project:settings:list --project=nobody')->expectsOutput("Project 'nobody' not found.")->assertExitCode(1);
    }

    public function test_set_refuses_bad_input(): void
    {
        $this->artisan('project:settings:set persist-paths a --value=b --project=alice')
            ->expectsOutput('Conflicting values: positional argument vs --value.')
            ->assertExitCode(1);
        $this->artisan('project:settings:set persist-paths uploads')->expectsOutput('--project is required.')->assertExitCode(1);
        $this->artisan('project:settings:set persist-paths --project=alice')
            ->expectsOutput('Value is required (positional argument or --value).')
            ->assertExitCode(1);
        $this->artisan('project:settings:set colour red --project=alice')
            ->expectsOutputToContain("Unknown project setting 'colour'.")
            ->assertExitCode(1);
        $this->artisan('project:settings:set persist-paths uploads --project=nobody')
            ->expectsOutput("Project 'nobody' not found.")
            ->assertExitCode(1);
    }

    public function test_set_saves_registry_logins_and_reports_a_value_it_cannot_parse(): void
    {
        $this->artisan('project:settings:set registry-auth --value="registry.example.test bob tok3n" --project=bob --force')
            ->expectsOutput('Registry logins saved for: registry.example.test.')
            ->assertExitCode(0);
        $this->assertSame('registry.example.test bob tok3n', User::findByUsername('bob')->getRegistryAuth());

        $this->artisan('project:settings:set registry-auth "   " --project=bob --force')
            ->expectsOutput("Failed to set setting: registry-auth needs at least one 'host username token' line.")
            ->assertExitCode(1);
        $this->artisan('project:settings:set protected off --project=bob --force')
            ->expectsOutput("Failed to set setting: protected takes only 'on'; unset it to turn protection off.")
            ->assertExitCode(1);
    }

    public function test_set_validates_a_cloudflare_token_and_names_its_account(): void
    {
        Http::fake(['api.cloudflare.com/client/v4/accounts*' => Http::response(['success' => true, 'result' => [['id' => 'acc-1', 'name' => 'Acme']]], 200)]);

        $this->artisan('project:settings:set cloudflare-api-token cf-token-value --project=alice --force')
            ->expectsOutput("About to set project setting 'cloudflare-api-token' for 'alice'.")
            ->expectsOutputToContain('Required permissions: Account Cloudflare Tunnel Edit')
            ->expectsOutput('Cloudflare API token saved.')
            ->expectsOutput('Account: Acme (acc-1)')
            ->assertExitCode(0);

        $this->assertSame('cf-token-value', User::findByUsername('alice')->getCloudflareApiToken());
    }

    public function test_set_reports_a_cloudflare_refusal(): void
    {
        $this->artisan('project:settings:set cloudflare-api-token cf-token-value --project=bob --force')
            ->expectsOutput("Cloudflare tunnels are only supported for DinD projects ('bob' is not DinD).")
            ->assertExitCode(1);
    }

    public function test_unset_clears_a_detail_and_refuses_bad_input(): void
    {
        $this->artisan('project:settings:unset persist-paths --project=alice --force')
            ->expectsOutputToContain('persist-paths')
            ->assertExitCode(0);
        $this->assertNull(User::findByUsername('alice')->getDetails()['persist_paths'] ?? null);

        $this->artisan('project:settings:unset persist-paths')->expectsOutput('--project is required.')->assertExitCode(1);
        $this->artisan('project:settings:unset colour --project=alice')
            ->expectsOutputToContain("Unknown project setting 'colour'.")
            ->assertExitCode(1);
        $this->artisan('project:settings:unset persist-paths --project=nobody')->expectsOutput("Project 'nobody' not found.")->assertExitCode(1);
    }

    public function test_unset_of_the_cloudflare_token_explains_itself_and_a_no_keeps_it(): void
    {
        $this->artisan('project:settings:unset cloudflare-api-token --project=alice')
            ->expectsOutputToContain('This clears the Cloudflare API token and connector state.')
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);
    }

    public function test_the_deprecated_token_command_forwards_to_settings_set(): void
    {
        Http::fake(['api.cloudflare.com/client/v4/accounts*' => Http::response(['success' => true, 'result' => [['id' => 'acc-2', 'name' => 'Beta']]], 200)]);

        $this->artisan('project:set-cloudflare-api-token --token=cf-other --project=alice --force')
            ->expectsOutput('project:set-cloudflare-api-token is deprecated; use project:settings:set cloudflare-api-token.')
            ->expectsOutput('Account: Beta (acc-2)')
            ->assertExitCode(0);

        $this->artisan('project:set-cloudflare-api-token one --token=two --project=alice')
            ->expectsOutput('Conflicting tokens: positional argument vs --token.')
            ->assertExitCode(1);
        $this->artisan('project:set-cloudflare-api-token --project=alice --force')
            ->expectsOutput('Value is required (positional argument or --value).')
            ->assertExitCode(1);
    }
}
