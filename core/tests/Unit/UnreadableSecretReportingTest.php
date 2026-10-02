<?php

namespace Tests\Unit;

use App\Http\Controllers\SystemController;
use App\Http\Resources\UserResource;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\AppHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * After an APP_KEY change a secret reads as empty, the next save stores it
 * that way, and until then reading the project says so. Only the server
 * report names the APP_KEY; a project only says what it cannot decode.
 */
class UnreadableSecretReportingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useAppKey(str_repeat('k', 32));
        Setting::setRuntimeSettings(['disable-user-ip-assign' => '1']);
        Ipv4NatMap::setNatModeEnabledOverride(false);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Ipv4NatMap::clearNatModeEnabledOverride();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, mixed, mixed, mixed}>
     */
    public static function secrets(): array
    {
        return [
            'git_token' => ['git_token', 'old-token', 'new-token', null],
            'cloudflare_api_token' => ['cloudflare_api_token', 'old-cf', 'new-cf', null],
            'cloudflare_tunnel_token' => ['cloudflare_tunnel_token', 'old-tunnel', 'new-tunnel', null],
            'site_password_hash' => ['site_password_hash', '$2y$10$old', '$2y$10$new', null],
            'env_vars' => ['env_vars', ['A' => 'old'], ['B' => 'new'], []],
            'app_credentials' => ['app_credentials', ['fields' => ['P' => 'old']], ['fields' => ['P' => 'new']], []],
        ];
    }

    #[DataProvider('secrets')]
    public function test_saving_after_a_key_change_stores_the_unreadable_secret_empty(string $key, mixed $old, mixed $new, mixed $empty): void
    {
        $user = $this->user([$key => $old]);
        $this->useAppKey(str_repeat('x', 32));
        $this->assertSame([$key], $user->unreadableSecrets());

        $user->setDetails(['git_commit' => 'abc']);

        $this->assertSame($empty, $user->getDetails()[$key]);
        $this->assertSame([], $user->unreadableSecrets());
    }

    #[DataProvider('secrets')]
    public function test_a_new_value_replaces_an_unreadable_secret(string $key, mixed $old, mixed $new, mixed $empty): void
    {
        $user = $this->user([$key => $old]);
        $this->useAppKey(str_repeat('x', 32));

        $user->setDetails([$key => $new]);

        $this->assertSame($new, $user->getDetails()[$key]);
        $this->assertSame([], $user->unreadableSecrets());
    }

    public function test_saving_after_a_key_change_drops_an_unreadable_site_git_token(): void
    {
        $user = $this->user([
            'site_git' => ['public_html' => ['repo_url' => 'https://github.com/o/r.git', 'branch' => 'main', 'token' => 'pat']],
        ]);
        $this->useAppKey(str_repeat('x', 32));
        $this->assertSame(['site_git.public_html.token'], $user->unreadableSecrets());

        $user->setDetails(['git_commit' => 'abc']);

        $this->assertNull($user->getDetails()['site_git']['public_html']['token']);
        $this->assertSame([], $user->unreadableSecrets());
    }

    public function test_the_project_resource_names_unreadable_secrets(): void
    {
        $user = $this->user([
            'git_token' => 'ghp-secret',
            'env_vars' => ['A' => 'b'],
            'site_git' => ['public_html' => ['repo_url' => 'https://github.com/o/r.git', 'branch' => 'main', 'token' => 'pat']],
        ]);
        $this->useAppKey(str_repeat('x', 32));

        $json = (new UserResource($user))->toArray(Request::create('/'));

        $this->assertSame(['git_token', 'env_vars', 'site_git.public_html.token'], $json['unreadable_secrets']);
        $this->assertStringContainsString('git_token, env_vars, site_git.public_html.token', (string) $json['warning']);
        $this->assertStringContainsString('cannot be decoded', (string) $json['warning']);
        $this->assertStringContainsString('next save of this project', (string) $json['warning']);
        $this->assertStringNotContainsString('database password', (string) $json['warning']);
        $this->assertStringNotContainsString('APP_KEY', (string) $json['warning']);
    }

    public function test_the_project_resource_is_quiet_when_every_secret_reads(): void
    {
        $user = $this->user(['git_token' => 'ghp-secret', AppDatabase::PASSWORD_DETAIL => 'db']);

        $json = (new UserResource($user))->toArray(Request::create('/'));

        $this->assertSame([], $json['unreadable_secrets']);
        $this->assertNull($json['warning']);
    }

    public function test_the_warning_says_the_app_database_password_is_kept(): void
    {
        $user = $this->user([AppDatabase::PASSWORD_DETAIL => 'db']);
        $this->useAppKey(str_repeat('x', 32));

        $this->assertSame([AppDatabase::PASSWORD_DETAIL], $user->unreadableSecrets());
        $this->assertStringContainsString('app database password is kept', (string) $user->unreadableSecretsWarning());
        $this->assertStringNotContainsString('APP_KEY', (string) $user->unreadableSecretsWarning());

        $user->setDetails(['git_commit' => 'abc']);
        $this->assertSame([AppDatabase::PASSWORD_DETAIL], $user->unreadableSecrets());
    }

    public function test_app_health_warns_about_unreadable_secrets(): void
    {
        $user = $this->user(['git_token' => 'ghp-secret']);
        $this->assertNull(AppHealth::unreadableSecretsCheck($user));

        $this->useAppKey(str_repeat('x', 32));
        $check = AppHealth::unreadableSecretsCheck($user);

        $this->assertSame(AppHealth::CHECK_SECRETS_READABLE, $check['id'] ?? null);
        $this->assertSame('fail', $check['status']);
        $this->assertSame('warning', $check['severity']);
        $this->assertSame(['secrets' => ['git_token']], $check['evidence']);
        $this->assertStringContainsString('git_update_credentials', $check['fix']);
        $this->assertSame('Stored secrets cannot be decoded.', $check['title']);
        $this->assertStringNotContainsString('APP_KEY', $check['detail'] . $check['fix']);
    }

    public function test_only_the_server_report_says_the_app_key_may_be_invalid(): void
    {
        $alice = $this->user(['git_token' => 'ghp-secret']);
        $bob = $this->user(['git_token' => 'ghp-secret'], 'bob');
        $this->useAppKey(str_repeat('x', 32));
        $carol = $this->user(['git_token' => 'readable'], 'carol');

        $report = SystemController::unreadableSecrets([$alice, $bob, $carol]);

        $this->assertSame(2, $report['count']);
        $this->assertSame(['alice', 'bob'], $report['projects']);
        $this->assertStringContainsString('APP_KEY may be invalid', (string) $report['warning']);
        $this->assertStringContainsString('alice, bob', (string) $report['warning']);
    }

    public function test_the_server_report_is_quiet_when_every_project_decodes(): void
    {
        $report = SystemController::unreadableSecrets([$this->user(['git_token' => 'ghp-secret'])]);

        $this->assertSame(['count' => 0, 'projects' => [], 'warning' => null], $report);
    }

    /** @param array<string, mixed> $details */
    private function user(array $details, string $username = 'alice'): User
    {
        $user = new User();
        $user->id = 1;
        $user->username = $username;
        $user->domain = $username . '.test';
        $user->status = 'active';
        $user->details = $details;
        $user->setRelation('liveUser', null);
        $user->setRelation('stagingUser', null);

        return $user;
    }

    private function useAppKey(string $key): void
    {
        config(['app.key' => 'base64:' . base64_encode($key)]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
    }
}
