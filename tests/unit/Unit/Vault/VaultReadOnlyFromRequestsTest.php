<?php

namespace Tests\Unit\Vault;

use App\Http\Middleware\Authenticate;
use App\Lib\Deploy\Telemetry\TelemetryFields;
use App\Models\SecretVaultEntry;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * The vault only proxies what a caller passes. A request that names
 * `vault:<id>` gets the secret, stored on the project as any value would be;
 * nothing else -- the User getters, settings, telemetry -- reads the vault,
 * so `use_count` stays 0 on every path that was not given a reference.
 */
class VaultReadOnlyFromRequestsTest extends VaultTestCase
{
    private const LOOPBACK_REPO = 'http://127.0.0.1:1/acme/app.git';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('sqlite')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('users');
        parent::tearDown();
    }

    public function test_source_inspect_without_a_token_reads_no_secret(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $this->withoutMiddleware(Authenticate::class);

        $response = $this->postJson('/api/source/inspect', ['source' => self::LOOPBACK_REPO, 'type' => 'git']);

        // Refused by the anonymous clone itself: nothing listens on port 1.
        $response->assertStatus(422);
        $this->assertSame(0, $entry->refresh()->use_count);
        $this->assertStringNotContainsString('ghp_shared', (string) $response->getContent());
    }

    public function test_source_inspect_uses_an_unowned_project_entry_without_assigning_it(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        $this->withoutMiddleware(Authenticate::class);

        // The clone itself fails (nothing listens on port 1); the token was handed to it.
        $this->postJson('/api/source/inspect', [
            'source' => 'https://127.0.0.1:1/acme/app.git',
            'type' => 'git',
            'git_token' => $entry->reference(),
        ])->assertStatus(422)->assertJsonMissingValidationErrors('git_token');

        $this->assertSame(1, $entry->refresh()->use_count);
        $this->assertNull($entry->project, 'Inspecting does not assign it to a project.');
    }

    public function test_source_inspect_refuses_a_project_entry_another_project_owns(): void
    {
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');
        $this->withoutMiddleware(Authenticate::class);

        $this->postJson('/api/source/inspect', [
            'source' => 'https://127.0.0.1:1/acme/app.git',
            'type' => 'git',
            'git_token' => $entry->reference(),
        ])->assertStatus(422)->assertJsonValidationErrors('git_token');

        $this->assertSame(0, $entry->refresh()->use_count);
    }

    public function test_a_create_refused_for_a_taken_name_does_not_assign_the_entry(): void
    {
        Queue::fake();
        $this->project([], 'shop');
        [$entry] = $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine');
        $this->withoutMiddleware(Authenticate::class);

        $this->postJson('/api/projects', [
            'username' => 'shop',
            'email' => 'p@example.com',
            'tunnel' => 'none',
            'git_repo' => 'https://127.0.0.1:1/acme/app.git',
            'git_token' => $entry->reference(),
        ])->assertStatus(422)->assertJsonPath('problems.0.code', 'name_taken');

        $this->assertNull($entry->refresh()->project, 'The name belongs to another project; it must not get the secret.');
    }

    public function test_a_project_given_no_token_has_none(): void
    {
        [$git] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        [$cf] = $this->globalEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf_shared');
        $user = $this->project(['git_repo' => 'https://github.com/acme/app.git']);

        $this->assertNull($user->getGitToken());
        $this->assertNull($user->getSiteGit('project')['token'] ?? null);
        $this->assertNull($user->getCloudflareApiToken());
        $this->assertFalse($this->cloudflareSetting($user)['set']);
        $this->assertSame(0, $git->refresh()->use_count);
        $this->assertSame(0, $cf->refresh()->use_count);
    }

    public function test_a_stored_value_is_never_looked_up_in_the_vault(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        // Whatever sits in the details is what the project has.
        $user = $this->project(['git_token' => $entry->reference(), 'cloudflare_api_token' => $entry->reference()]);

        $this->assertSame($entry->reference(), $user->getGitToken());
        $this->assertSame($entry->reference(), $user->getCloudflareApiToken());
        $this->cloudflareSetting($user);
        $this->assertSame(0, $entry->refresh()->use_count);
    }

    public function test_telemetry_does_not_read_the_vault_to_judge_privacy(): void
    {
        [$entry] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $this->project(['git_repo' => 'https://github.com/acme/app.git']);
        $this->project(['git_repo' => 'https://github.com/acme/private.git', 'git_token' => 'ghp_copied'], 'referrer');

        $this->assertFalse((new TelemetryFields('alice'))->repoIsPrivate());
        $this->assertTrue((new TelemetryFields('referrer'))->repoIsPrivate());
        $this->assertSame(0, $entry->refresh()->use_count);
    }

    /** @return array{key: string, value: ?string, set: bool, secret: bool} */
    private function cloudflareSetting(User $user): array
    {
        return (new Settings(new Project(new System(), $user)))->get(Settings::KEY_CLOUDFLARE_API_TOKEN);
    }

    /** @param array<string, mixed> $details */
    private function project(array $details, string $username = 'alice'): User
    {
        /** @var User */
        return User::query()->create([
            'username' => $username,
            'domain' => $username . '.test',
            'email' => $username . '@example.com',
            'details' => $details,
        ]);
    }
}
