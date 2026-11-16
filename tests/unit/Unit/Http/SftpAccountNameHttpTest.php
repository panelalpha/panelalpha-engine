<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\SftpAccount;
use App\System;
use App\System\Services\Sftp;
use ArrayObject;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** An SFTP account is named with or without its project prefix, as a MySQL user is, and holds only a key the SFTP server takes. */
class SftpAccountNameHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const RSA_1024 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQDkI2XmjUrsnhH62flwcGdiTOueAB4iXG9kUm36sHtJGPAvHGoyiAf7e0SlYt1O4VKvS4JdUFN5Kj8TomNg7hLqMdb8Y1ipRPUZZBINdaWvdlzuQOhLeWZoeavq1naeOig4pTkOqDcxF6YJo0MWnWmqKnVmtkSmxNS8wmLNJrEFnQ== user@laptop';

    private const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIN5KCiuy5+pUCc2xXv4dhmQWvU/VtCA/OmjHI8mwa5l user@laptop';

    private ArrayObject $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->events = new ArrayObject();
        $this->app->instance(System::class, $this->recordingSystem());
    }

    public function test_a_bare_name_is_stored_with_the_project_prefix(): void
    {
        $this->makeUser('alice');

        $this->create('backup')->assertSuccessful()->assertJsonPath('data.username', 'alice_backup');

        $this->assertSame(['alice_backup'], SftpAccount::query()->pluck('username')->all());
        $this->assertSame([['rebuild']], $this->events->getArrayCopy());
    }

    public function test_a_prefixed_name_is_stored_as_sent(): void
    {
        $this->makeUser('alice');

        $this->create('alice_backup')->assertSuccessful()->assertJsonPath('data.username', 'alice_backup');
    }

    public function test_a_bare_name_finds_the_account_it_created(): void
    {
        $this->makeUser('alice');
        $this->create('backup')->assertSuccessful();

        $this->deleteJson('/api/projects/alice/sftp-accounts/backup')->assertOk();

        $this->assertSame(0, SftpAccount::query()->count());
    }

    public function test_a_name_too_long_with_its_prefix_is_refused(): void
    {
        $this->makeUser('alice');

        $this->create(str_repeat('b', 30))
            ->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'Username cannot be longer than 32 characters with its alice_ prefix.');
    }

    public function test_a_name_over_the_limit_before_its_prefix_gets_the_plain_length_refusal(): void
    {
        $this->makeUser('alice');

        $this->create(str_repeat('b', 33))
            ->assertUnprocessable()
            ->assertJsonPath('errors.username', ['Username cannot be longer than 32 characters.']);
    }

    public function test_an_unknown_project_is_not_found_whatever_the_name(): void
    {
        $this->create(str_repeat('b', 30))->assertNotFound();
    }

    public function test_a_weak_key_is_refused_on_create_with_its_code(): void
    {
        $this->makeUser('alice');

        $this->createWithKey('backup', self::RSA_1024)
            ->assertUnprocessable()
            ->assertJsonPath('errors.public_key', ['RSA keys must be at least 2048 bits; this one is 1024.'])
            ->assertJsonPath('problems.0.code', 'ssh_key_too_weak');

        $this->assertSame(0, SftpAccount::query()->count());
    }

    public function test_a_weak_key_is_refused_on_update(): void
    {
        $this->makeUser('alice');
        $this->createWithKey('backup', self::ED25519)->assertSuccessful();

        $this->putJson('/api/projects/alice/sftp-accounts/backup', ['auth_method' => 'public_key', 'public_key' => self::RSA_1024])
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'ssh_key_too_weak');

        $this->assertSame(self::ED25519, SftpAccount::query()->value('public_key'));
    }

    private function create(string $name): TestResponse
    {
        return $this->postJson('/api/projects/alice/sftp-accounts', [
            'sftp_username' => $name,
            'auth_method' => 'password',
            'password' => 'secret-pass',
        ]);
    }

    private function createWithKey(string $name, string $key): TestResponse
    {
        return $this->postJson('/api/projects/alice/sftp-accounts', [
            'sftp_username' => $name,
            'auth_method' => 'public_key',
            'public_key' => $key,
        ]);
    }

    private function recordingSystem(): System
    {
        return new class ($this->events) extends System {
            public function __construct(private ArrayObject $events)
            {
            }

            public function sftp(): Sftp
            {
                return new class ($this, $this->events) extends Sftp {
                    public function __construct(System $system, private ArrayObject $events)
                    {
                        parent::__construct($system);
                    }

                    public function killSessions(array $logins): int
                    {
                        return 0;
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
