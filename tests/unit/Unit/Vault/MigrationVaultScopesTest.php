<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The upgrade to `project`/`global` scopes: request slots go, the table loses
 * the secret's expiry is kept (optional now), it gains `project`, and every project that was using a global
 * -- by a stored `vault:` reference or through the old implicit fallback --
 * gets that secret stored on it, since nothing reads the vault for it now.
 */
class MigrationVaultScopesTest extends VaultTestCase
{
    private const RETIRED_SETTING = 'vault-project-scoped-tokens';

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        // The table as it was before this migration.
        Schema::connection('sqlite')->drop('secret_vault_entries');
        Schema::connection('sqlite')->create('secret_vault_entries', function (Blueprint $table) {
            $table->id();
            $table->char('ref_hash', 64)->unique();
            $table->string('type');
            $table->string('scope', 16)->default('request');
            $table->string('purpose', 255)->nullable();
            $table->json('verify_with')->nullable();
            $table->json('verification')->nullable();
            $table->text('secret_encrypted')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('link_expires_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('tunnels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            $table->timestamps();
        });

        $this->migration = require base_path('database/migrations/2026_09_24_100000_vault_project_and_global_scopes.php');
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('tunnels');
        Schema::connection('sqlite')->dropIfExists('users');
        parent::tearDown();
    }

    public function test_the_table_takes_the_new_shape_and_request_slots_go(): void
    {
        [$request] = $this->entry(['scope' => 'request', 'secret' => 'ghp_copied_long_ago']);
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');

        $this->migration->up();

        $this->assertTrue(Schema::hasColumn('secret_vault_entries', 'project'));
        $this->assertTrue(Schema::hasColumn('secret_vault_entries', 'expires_at'), 'The secret expiry stays, optional.');
        $this->assertNull(SecretVaultEntry::query()->find($request->id));
        $this->assertNotNull(SecretVaultEntry::query()->find($global->id));

        [$fresh] = $this->projectEntry();
        $this->assertSame(SecretVaultEntry::SCOPE_PROJECT, $fresh->refresh()->scope);
    }

    public function test_a_project_that_used_the_global_now_holds_its_secret(): void
    {
        [$older] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_older');
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $inheriting = $this->project('inherits', ['git_repo' => 'https://github.com/acme/app.git']);
        $blank = $this->project('blank', ['git_repo' => 'https://github.com/acme/app.git', 'git_token' => '']);
        $named = $this->project('named', ['git_token' => 'vault:global']);
        $byId = $this->project('byid', ['git_token' => 'vault:' . $older->id]);

        $this->migration->up();

        foreach ([$inheriting, $blank, $named] as $user) {
            $this->assertSame('ghp_shared', $user->refresh()->getGitToken(), 'The newest global, as the old code used.');
        }
        $this->assertSame('ghp_older', $byId->refresh()->getGitToken(), 'A stored vault:<id> becomes that entry\'s secret.');
    }

    public function test_projects_that_did_not_use_a_global_are_left_alone(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $own = $this->project('own', ['git_repo' => 'https://github.com/acme/app.git', 'git_token' => 'ghp_mine']);
        $noRepo = $this->project('norepo', ['template' => 'dind']);

        $this->migration->up();

        $this->assertSame('ghp_mine', $own->refresh()->getGitToken());
        $this->assertNull($noRepo->refresh()->getGitToken());
    }

    public function test_sharing_switched_off_meant_no_fallback_and_the_switch_goes(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        Setting::set(self::RETIRED_SETTING, '1');
        $scoped = $this->project('scoped', ['git_repo' => 'https://github.com/acme/app.git']);
        $named = $this->project('named', ['git_token' => 'vault:global']);

        $this->migration->up();

        $this->assertNull($scoped->refresh()->getGitToken());
        $this->assertSame('ghp_shared', $named->refresh()->getGitToken(), 'Named explicitly: kept.');
        $this->assertNull(Setting::get(self::RETIRED_SETTING));
    }

    public function test_an_unusable_global_records_nothing_and_clears_vault_global(): void
    {
        $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN); // minted, never pasted
        $inheriting = $this->project('unpasted', ['git_repo' => 'https://github.com/acme/app.git']);
        $named = $this->project('named', ['git_token' => 'vault:global']);

        $this->migration->up();

        $this->assertNull($inheriting->refresh()->getGitToken());
        $this->assertNull($named->refresh()->getGitToken(), 'A dead vault:global must not survive as a literal token.');
    }

    public function test_a_project_with_cloudflare_state_keeps_the_cloudflare_token(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'cf_shared');
        $byAccount = $this->project('byaccount', ['cloudflare_account_id' => 'acc123']);
        $byTunnel = $this->project('bytunnel', []);
        DB::table('tunnels')->insert(['user_id' => $byTunnel->id, 'provider' => 'cloudflare']);
        $untouched = $this->project('nocf', []);
        $own = $this->project('owncf', ['cloudflare_account_id' => 'acc9', 'cloudflare_api_token' => 'cf_mine']);

        $this->migration->up();

        $this->assertSame('cf_shared', $byAccount->refresh()->getCloudflareApiToken());
        $this->assertSame('cf_shared', $byTunnel->refresh()->getCloudflareApiToken());
        $this->assertNull($untouched->refresh()->getCloudflareApiToken());
        $this->assertSame('cf_mine', $own->refresh()->getCloudflareApiToken());
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $user = $this->project('twice', ['git_repo' => 'https://github.com/acme/app.git']);

        $this->migration->up();
        $this->migration->up();

        $this->assertSame('ghp_shared', $user->refresh()->getGitToken());
        $this->assertSame(0, $global->refresh()->use_count, 'Checked for decryptability, never counted as a use.');
    }

    /** @param array<string, mixed> $details */
    private function project(string $username, array $details): User
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
