<?php

namespace Tests\Unit\Vault;

use App\Http\Requests\ProjectSettingUpdateRequest;
use App\Lib\Vault\ProjectSettingVaultTypes;
use App\Models\SecretVaultEntry;
use App\Models\User;
use App\System\Project\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * `project_setting_set` takes a credential, so a secret setting accepts a
 * `vault:<key>`: the request reads it for this project and the controller
 * stores the secret, exactly as if the caller had sent it.
 */
class ProjectSettingVaultTest extends VaultTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('sqlite')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        User::query()->create(['username' => 'shop']);
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('users');
        parent::tearDown();
    }

    public function test_a_cloudflare_token_is_a_secret_setting(): void
    {
        $this->assertTrue(Settings::isSecret('cloudflare-api-token'));
    }

    public function test_every_secret_setting_has_a_vault_type_and_no_other_setting_does(): void
    {
        $this->assertSame(Settings::SECRET_KEYS, array_keys(ProjectSettingVaultTypes::TYPES));
        $this->assertSame(SecretVaultEntry::TYPE_REGISTRY_AUTH, ProjectSettingVaultTypes::typeFor(' Registry-Auth '));
        $this->assertNull(ProjectSettingVaultTypes::typeFor('persist-paths'));
    }

    public function test_a_reference_is_stored_as_its_secret_and_assigned_to_the_project(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-real-token-value');

        $this->assertSame('cf-real-token-value', $this->validatedValue('cloudflare-api-token', $entry->reference()));
        $entry->refresh();
        $this->assertSame('shop', $entry->project);
        $this->assertSame(1, (int) $entry->use_count);
    }

    public function test_a_literal_passes_through(): void
    {
        $this->assertSame('a-literal-token', $this->validatedValue('cloudflare-api-token', 'a-literal-token'));
    }

    public function test_a_git_token_entry_is_refused_as_a_cloudflare_token(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_not_cloudflare');

        $this->assertRefusedOnValue('cloudflare-api-token', $entry->reference(), "holds a 'git_token'");
    }

    public function test_another_projects_entry_is_refused(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-theirs', 'blog');

        $this->assertRefusedOnValue('cloudflare-api-token', $entry->reference(), "belongs to project 'blog'");
        $this->assertSame('blog', $entry->refresh()->project);
    }

    public function test_a_non_secret_key_keeps_a_reference_as_a_literal_and_leaves_the_entry_unclaimed(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-not-for-settings');

        $this->assertSame($entry->reference(), $this->validatedValue('persist-paths', $entry->reference()));

        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }

    public function test_a_reference_inside_a_non_string_value_is_refused_by_the_rules_and_left_unclaimed(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf-in-an-array');

        $this->assertRefusedOnValue('cloudflare-api-token', [$entry->reference()], 'must be a string');

        $entry->refresh();
        $this->assertNull($entry->project);
        $this->assertSame(0, (int) $entry->use_count);
    }

    private function validatedValue(string $key, mixed $value): mixed
    {
        $request = $this->request($key, $value);
        $request->validateResolved();

        return $request->validated('value');
    }

    private function assertRefusedOnValue(string $key, mixed $value, string $message): void
    {
        try {
            $this->request($key, $value)->validateResolved();
            $this->fail('expected a 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->errors()['value'][0]);
        }
    }

    private function request(string $key, mixed $value): ProjectSettingUpdateRequest
    {
        $uri = "/api/projects/shop/settings/{$key}";
        $request = ProjectSettingUpdateRequest::create($uri, 'PUT', ['value' => $value]);
        $route = (new Route('PUT', 'api/projects/{username}/settings/{key}', []))->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));

        return $request;
    }
}
