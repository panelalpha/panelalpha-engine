<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Limits\ResourceLimit;
use App\Models\FtpAccount;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/** /projects/{project}/ftp-accounts: an account is created, changed and removed on the FTP server and in the database together. */
class FtpAccountHttpTest extends TestCase
{
    use InMemoryDatabase;

    private ScriptedBroker $broker;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        $this->alice = $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001], 'shop.test');
        $this->makeMainDomain($this->alice, 'shop.test');
    }

    protected function tearDown(): void
    {
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_the_accounts_of_a_project_are_listed(): void
    {
        $this->account('deploy@shop.test');

        $this->getJson('/api/projects/alice/ftp-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.user', 'deploy@shop.test')
            ->assertJsonPath('data.0.directory', '/');
    }

    public function test_a_directory_that_is_not_in_the_home_is_refused_before_the_server_is_asked(): void
    {
        $this->postJson('/api/projects/alice/ftp-accounts', [
            'user' => 'deploy',
            'domain' => 'shop.test',
            'password' => 'secret-pass-1',
            'directory' => '/no-such-dir-' . bin2hex(random_bytes(4)),
            'quota' => 100,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.directory.0', 'The directory must be an existing directory inside the project home.');

        $this->assertSame(0, FtpAccount::query()->count());
        $this->assertSame([], $this->broker->requests());
    }

    public function test_a_domain_the_project_does_not_hold_is_refused(): void
    {
        $this->postJson('/api/projects/alice/ftp-accounts', ['user' => 'deploy', 'domain' => 'other.test', 'password' => 'secret-pass-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Invalid value.');

        $this->assertSame([], $this->broker->requests());
    }

    public function test_a_taken_login_is_refused(): void
    {
        $this->account('deploy@shop.test');

        $this->postJson('/api/projects/alice/ftp-accounts', ['user' => 'deploy', 'domain' => 'shop.test', 'password' => 'secret-pass-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'User already exists.');
    }

    public function test_an_account_over_the_limit_is_refused(): void
    {
        $this->alice->setLimit(ResourceLimit::byKey('ftp_accounts_limit'), 1);
        $this->alice->save();
        $this->account('first@shop.test');

        $this->postJson('/api/projects/alice/ftp-accounts', ['user' => 'deploy', 'domain' => 'shop.test', 'password' => 'secret-pass-1'])
            ->assertUnprocessable()
            ->assertJsonPath('error_type', 'ftp_accounts_limit_reached')
            ->assertJsonPath('message', 'FTP accounts limit of 1 reached.');
    }

    public function test_an_unknown_project_is_not_found(): void
    {
        $this->getJson('/api/projects/nobody/ftp-accounts')->assertNotFound()->assertExactJson(['message' => 'Project not found']);
        $this->postJson('/api/projects/nobody/ftp-accounts', ['user' => 'deploy', 'domain' => 'shop.test', 'password' => 'secret-pass-1'])
            ->assertNotFound();
    }

    public function test_an_update_without_a_quota_keeps_the_current_one(): void
    {
        $this->account('deploy@shop.test', ['unlimited_quota' => false, 'quota' => 50]);

        $this->putJson('/api/projects/alice/ftp-accounts/deploy@shop.test', ['password' => 'new-secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.details.quota', 50)
            ->assertJsonPath('data.details.unlimited_quota', false);
        $this->assertStringContainsString('deploy@shop.test', implode(' ', array_merge(...$this->broker->requests())));
    }

    public function test_an_update_with_a_quota_replaces_it(): void
    {
        $this->account('deploy@shop.test', ['unlimited_quota' => true, 'quota' => -1]);

        $this->putJson('/api/projects/alice/ftp-accounts/deploy@shop.test', ['quota' => 200])
            ->assertOk()
            ->assertJsonPath('data.details.quota', 200)
            ->assertJsonPath('data.details.unlimited_quota', false);
        $this->assertSame(200, FtpAccount::query()->first()?->getDetails()['quota']);
    }

    public function test_an_account_is_deleted_from_the_server_and_the_database(): void
    {
        $this->account('deploy@shop.test');

        $this->deleteJson('/api/projects/alice/ftp-accounts/deploy@shop.test')->assertOk()->assertJsonPath('data.user', 'deploy@shop.test');

        $this->assertSame(0, FtpAccount::query()->count());
        $this->assertNotSame([], $this->broker->requests());
    }

    public function test_an_unknown_account_is_not_found(): void
    {
        $this->putJson('/api/projects/alice/ftp-accounts/ghost@shop.test', ['quota' => 1])
            ->assertNotFound()
            ->assertExactJson(['message' => 'FTP account not found']);
        $this->deleteJson('/api/projects/alice/ftp-accounts/ghost@shop.test')
            ->assertNotFound()
            ->assertExactJson(['message' => 'FTP account not found']);
    }

    /** @param array<string, mixed> $details */
    private function account(string $login, array $details = ['unlimited_quota' => true, 'quota' => -1]): FtpAccount
    {
        return FtpAccount::create(['user_id' => $this->alice->id, 'user' => $login, 'directory' => '/', 'details' => $details]);
    }
}
