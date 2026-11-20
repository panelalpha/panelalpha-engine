<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\SftpAccount;
use App\Models\User;
use App\System;
use App\System\Services\Sftp;
use ArrayObject;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** GET and PUT on a project's SFTP accounts: the list, the credentials an update stores, the rebuild it triggers, and the limit on new ones. */
class SftpAccountUpdateHttpTest extends TestCase
{
    use InMemoryDatabase;

    private ArrayObject $events;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->events = new ArrayObject();
        $events = $this->events;
        $this->app->instance(System::class, new class($events) extends System
        {
            public function __construct(private ArrayObject $events) {}

            public function sftp(): Sftp
            {
                return new class($this, $this->events) extends Sftp
                {
                    public function __construct(System $system, private ArrayObject $events)
                    {
                        parent::__construct($system);
                    }

                    public function rebuildSftpAccounts(): void
                    {
                        $this->events->append('rebuild');
                    }
                };
            }
        });
        $this->alice = $this->makeUser('alice');
        SftpAccount::query()->create(['user_id' => $this->alice->id, 'username' => 'alice_deploy', 'auth_method' => 'password', 'password' => 'old-hash']);
    }

    private static function publicKey(): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat("\x07", 32)).' deploy@example.test';
    }

    public function test_the_accounts_of_a_project_are_listed(): void
    {
        $this->getJson('/api/projects/alice/sftp-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.username', 'alice_deploy')
            ->assertJsonPath('data.0.auth_method', 'password');

        $this->getJson('/api/projects/nobody/sftp-accounts')->assertNotFound()->assertExactJson(['message' => 'Project not found']);
    }

    public function test_a_new_password_is_stored_hashed_and_the_logins_rebuilt(): void
    {
        $this->putJson('/api/projects/alice/sftp-accounts/deploy', ['auth_method' => 'password', 'password' => 'new-secret-1'])
            ->assertOk()
            ->assertJsonPath('data.username', 'alice_deploy');

        $stored = SftpAccount::query()->sole()->getAttributes()['password'];
        $this->assertStringStartsWith('$6$rounds=5000$', $stored);
        $this->assertSame($stored, crypt('new-secret-1', $stored));
        $this->assertSame(['rebuild'], $this->events->getArrayCopy());
    }

    public function test_a_key_replaces_the_password_method_and_keeps_the_stored_password(): void
    {
        $this->putJson('/api/projects/alice/sftp-accounts/alice_deploy', ['auth_method' => 'public_key', 'public_key' => self::publicKey()])
            ->assertOk();

        $account = SftpAccount::query()->sole();
        $this->assertSame('public_key', $account->auth_method);
        $this->assertSame(self::publicKey(), $account->public_key);
        $this->assertSame('old-hash', $account->getAttributes()['password']);
        $this->assertSame(['rebuild'], $this->events->getArrayCopy());
    }

    public function test_an_unknown_account_or_method_changes_nothing(): void
    {
        $this->putJson('/api/projects/alice/sftp-accounts/other', ['auth_method' => 'password', 'password' => 'new-secret-1'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Account not found']);
        $this->putJson('/api/projects/alice/sftp-accounts/deploy', ['auth_method' => 'telnet'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.auth_method.0', 'Invalid auth_method');

        $this->assertSame([], $this->events->getArrayCopy());
        $this->assertSame('password', SftpAccount::query()->sole()->auth_method);
    }

    public function test_a_project_at_its_account_limit_is_refused_another(): void
    {
        $this->alice->setSftpAccountsLimit(1);
        $this->alice->save();

        $this->postJson('/api/projects/alice/sftp-accounts', ['sftp_username' => 'second', 'auth_method' => 'password', 'password' => 'secret-pass'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'SFTP accounts limit of 1 reached.', 'error_type' => 'sftp_accounts_limit_reached']);

        $this->assertSame(1, SftpAccount::query()->count());
    }
}
