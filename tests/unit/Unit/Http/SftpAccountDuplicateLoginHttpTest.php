<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\SftpAccount;
use App\System;
use App\System\Services\Sftp;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Concurrent creates of one login all pass the name check; only one may be stored, and the logins.conf rebuilt from
 * the rows keeps no second line, with its own password, that a delete would leave behind.
 */
class SftpAccountDuplicateLoginHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = 'database/migrations/2026_11_13_000000_add_unique_username_to_sftp_accounts_table.php';

    private ArrayObject $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->events = new ArrayObject;
        $this->app->instance(System::class, $this->recordingSystem());
    }

    public function test_a_create_that_loses_the_race_is_refused_like_a_taken_name(): void
    {
        $user = $this->makeUser('alice');
        SftpAccount::creating(static function (SftpAccount $account) use ($user): void {
            if (DB::table('sftp_accounts')->where('username', $account->username)->doesntExist()) {
                DB::table('sftp_accounts')->insert(['user_id' => $user->id, 'username' => $account->username, 'auth_method' => 'password']);
            }
        });

        $this->postJson('/api/projects/alice/sftp-accounts', [
            'sftp_username' => 'race',
            'auth_method' => 'password',
            'password' => 'secret-pass',
        ])->assertUnprocessable()->assertJsonPath('errors.username.0', 'Username not available');

        $this->assertSame(['alice_race'], SftpAccount::query()->pluck('username')->all());
        $this->assertSame([], $this->events->getArrayCopy());
    }

    public function test_a_delete_removes_every_row_of_the_login(): void
    {
        $user = $this->makeUser('alice');
        (require base_path(self::MIGRATION))->down();
        foreach (['first', 'second'] as $password) {
            DB::table('sftp_accounts')->insert(['user_id' => $user->id, 'username' => 'alice_race', 'auth_method' => 'password', 'password' => $password]);
        }

        $this->deleteJson('/api/projects/alice/sftp-accounts/race')->assertOk();

        $this->assertSame(0, SftpAccount::query()->where('username', 'alice_race')->count());
        $this->assertSame(['rebuild'], $this->events->getArrayCopy());
    }

    private function recordingSystem(): System
    {
        return new class($this->events) extends System
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

                    public function killSessions(array $logins): int
                    {
                        return 0;
                    }

                    public function rebuildSftpAccounts(): void
                    {
                        $this->events->append('rebuild');
                    }
                };
            }
        };
    }
}
