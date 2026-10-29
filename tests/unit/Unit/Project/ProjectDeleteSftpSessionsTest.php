<?php

namespace Tests\Unit\Project;

use App\Models\SftpAccount;
use App\Models\User;
use App\System;
use App\System\Services\Sftp;
use ArrayObject;
use ReflectionMethod;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Deleting a project's SFTP logins ends their open sessions before the logins
 * are rebuilt without them, and a sessions kill that fails does not stop it.
 */
class ProjectDeleteSftpSessionsTest extends TestCase
{
    use InMemoryDatabase;

    private ArrayObject $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->events = new ArrayObject();
    }

    public function test_every_login_of_the_project_has_its_sessions_ended_before_the_rebuild(): void
    {
        $user = $this->projectWithLogins('alice', ['alice_web', 'alice_backup']);
        $this->makeUser('bob');

        $this->deleteSftpLogins($this->recordingSystem(), $user);

        [$kill, $rebuild] = $this->events->getArrayCopy();
        sort($kill[1]);
        $this->assertSame(['kill', ['alice_backup', 'alice_web']], $kill);
        $this->assertSame(['apply', ''], $rebuild);
        $this->assertSame(0, SftpAccount::query()->count());
    }

    public function test_a_failed_kill_still_deletes_the_logins(): void
    {
        $user = $this->projectWithLogins('alice', ['alice_web']);

        $this->deleteSftpLogins($this->recordingSystem(throwOnKill: true), $user);

        $this->assertSame([['kill', ['alice_web']], ['apply', '']], $this->events->getArrayCopy());
        $this->assertSame(0, SftpAccount::query()->count());
    }

    public function test_a_project_without_logins_touches_no_session(): void
    {
        $user = $this->makeUser('alice');

        $this->deleteSftpLogins($this->recordingSystem(), $user);

        $this->assertSame([], $this->events->getArrayCopy());
    }

    private function deleteSftpLogins(System $system, User $user): void
    {
        $method = new ReflectionMethod(System\Project::class, 'deleteSftpLogins');
        $method->invoke($system->project($user), $user);
    }

    /** @param list<string> $logins */
    private function projectWithLogins(string $username, array $logins): User
    {
        $user = $this->makeUser($username);
        foreach ($logins as $login) {
            SftpAccount::create([
                'user_id' => $user->id,
                'username' => $login,
                'auth_method' => 'public_key',
                'public_key' => 'ssh-ed25519 AAAA',
            ]);
        }

        return $user;
    }

    private function recordingSystem(bool $throwOnKill = false): System
    {
        return new class ($throwOnKill, $this->events) extends System {
            public function __construct(private bool $throwOnKill, private ArrayObject $events)
            {
            }

            public function sftp(): Sftp
            {
                return new class ($this, $this->throwOnKill, $this->events) extends Sftp {
                    public function __construct(System $system, private bool $throwOnKill, private ArrayObject $events)
                    {
                        parent::__construct($system);
                    }

                    public function killSessions(array $logins): int
                    {
                        $this->events->append(['kill', $logins]);
                        if ($this->throwOnKill) {
                            throw new \RuntimeException('the sftp container is not running');
                        }

                        return count($logins);
                    }

                    public function applyLogins(string $config): void
                    {
                        $this->events->append(['apply', $config]);
                    }
                };
            }
        };
    }
}
