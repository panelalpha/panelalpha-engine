<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\SftpAccount;
use App\Models\User;
use App\System;
use App\System\Services\Sftp;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The two kill-sessions endpoints answer how many sessions were open and hand
 * the service the logins the route is about; deleting an account ends its
 * sessions before the logins rebuild, so the session cannot outlive the row.
 */
class SftpSessionKillHttpTest extends TestCase
{
    use InMemoryDatabase;

    private \ArrayObject $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);

        $this->events = new \ArrayObject();
        $this->app->instance(System::class, $this->recordingSystem());
    }

    public function test_one_account_kill_answers_the_count_and_names_only_that_login(): void
    {
        $user = $this->user('alice');
        $this->sftpAccount($user, 'alice_backup');
        $this->sftpAccount($user, 'alice_web');

        $response = $this->postJson('/api/projects/alice/sftp-accounts/alice_backup/kill-sessions');

        $response->assertOk()->assertJsonPath('data.killed', 5);
        $this->assertSame([['kill', ['alice_backup']]], $this->events->getArrayCopy());
    }

    public function test_project_wide_kill_names_every_login_and_answers_the_count(): void
    {
        $user = $this->user('alice');
        $this->sftpAccount($user, 'alice_backup');
        $this->sftpAccount($user, 'alice_web');

        $response = $this->postJson('/api/projects/alice/sftp-accounts/kill-sessions');

        $response->assertOk()->assertJsonPath('data.killed', 5);
        [$event, $logins] = $this->events->getArrayCopy()[0];
        sort($logins);
        $this->assertSame('kill', $event);
        $this->assertSame(['alice_backup', 'alice_web'], $logins);
    }

    public function test_no_open_session_answers_zero(): void
    {
        $user = $this->user('alice');
        $this->sftpAccount($user, 'alice_backup');
        $this->app->instance(System::class, $this->recordingSystem(answer: 0));

        $response = $this->postJson('/api/projects/alice/sftp-accounts/alice_backup/kill-sessions');

        $response->assertOk()->assertJsonPath('data.killed', 0);
    }

    public function test_an_unknown_project_is_a_404(): void
    {
        $this->user('alice');

        $this->postJson('/api/projects/nobody/sftp-accounts/kill-sessions')
            ->assertNotFound()->assertJsonPath('message', 'Project not found');
        $this->postJson('/api/projects/nobody/sftp-accounts/alice_backup/kill-sessions')
            ->assertNotFound()->assertJsonPath('message', 'Project not found');

        $this->assertSame([], $this->events->getArrayCopy());
    }

    public function test_an_unknown_or_foreign_account_is_a_404(): void
    {
        $alice = $this->user('alice');
        $this->sftpAccount($alice, 'alice_backup');
        $bob = $this->user('bob');
        $this->sftpAccount($bob, 'bob_backup');

        $this->postJson('/api/projects/alice/sftp-accounts/ghost/kill-sessions')
            ->assertNotFound()->assertJsonPath('message', 'Account not found');
        $this->postJson('/api/projects/alice/sftp-accounts/bob_backup/kill-sessions')
            ->assertNotFound()->assertJsonPath('message', 'Account not found');

        $this->assertSame([], $this->events->getArrayCopy());
    }

    public function test_deleting_an_account_kills_its_sessions_before_the_rebuild(): void
    {
        $user = $this->user('alice');
        $this->sftpAccount($user, 'alice_backup');

        $this->deleteJson('/api/projects/alice/sftp-accounts/alice_backup')->assertOk();

        $this->assertSame([['kill', ['alice_backup']], ['rebuild']], $this->events->getArrayCopy());
        $this->assertSame(0, SftpAccount::query()->where('username', 'alice_backup')->count());
    }

    public function test_a_delete_still_removes_the_account_when_the_kill_fails(): void
    {
        $user = $this->user('alice');
        $this->sftpAccount($user, 'alice_backup');
        $this->app->instance(System::class, $this->recordingSystem(throwOnKill: true));

        $this->deleteJson('/api/projects/alice/sftp-accounts/alice_backup')->assertOk();

        $this->assertSame([['kill', ['alice_backup']], ['rebuild']], $this->events->getArrayCopy());
        $this->assertSame(0, SftpAccount::query()->where('username', 'alice_backup')->count());
    }

    private function user(string $username): User
    {
        return $this->makeUser($username);
    }

    private function sftpAccount(User $user, string $username): SftpAccount
    {
        /** @var SftpAccount $account */
        $account = SftpAccount::create([
            'user_id' => $user->id,
            'username' => $username,
            'auth_method' => 'public_key',
            'public_key' => 'ssh-ed25519 AAAA',
        ]);

        return $account;
    }

    private function recordingSystem(int $answer = 5, bool $throwOnKill = false): System
    {
        return new class ($answer, $throwOnKill, $this->events) extends System {
            public function __construct(
                private int $answer,
                private bool $throwOnKill,
                private \ArrayObject $events,
            ) {
            }

            public function sftp(): Sftp
            {
                return new class ($this, $this->answer, $this->throwOnKill, $this->events) extends Sftp {
                    public function __construct(
                        System $system,
                        private int $answer,
                        private bool $throwOnKill,
                        private \ArrayObject $events,
                    ) {
                        parent::__construct($system);
                    }

                    public function killSessions(array $logins): int
                    {
                        $this->events->append(['kill', $logins]);
                        if ($this->throwOnKill) {
                            throw new \RuntimeException('the sftp container is not running');
                        }

                        return $this->answer;
                    }

                    public function rebuildSftpAccounts(): void
                    {
                        $this->events->append(['rebuild']);
                    }
                };
            }
        };
    }
}
