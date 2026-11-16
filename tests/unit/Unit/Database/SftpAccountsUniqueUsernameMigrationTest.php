<?php

namespace Tests\Unit\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A database that stored one login more than once keeps its lowest-id row of each, and then refuses another. */
class SftpAccountsUniqueUsernameMigrationTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = 'database/migrations/2026_11_13_000000_add_unique_username_to_sftp_accounts_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->migration()->down();
    }

    public function test_each_duplicated_login_keeps_its_lowest_id_row(): void
    {
        $alice = $this->makeUser('alice')->id;
        $bob = $this->makeUser('bob')->id;
        $kept = $this->account($alice, 'alice_race', 'first');
        $this->account($alice, 'alice_race', 'second');
        $this->account($alice, 'alice_race', 'third');
        $other = $this->account($alice, 'alice_web', 'web');
        $keptBob = $this->account($bob, 'bob_x', 'one');
        $this->account($bob, 'BOB_X', 'two');

        $this->migration()->up();

        $this->assertSame(
            [[$kept, 'alice_race', 'first'], [$other, 'alice_web', 'web'], [$keptBob, 'bob_x', 'one']],
            DB::table('sftp_accounts')->orderBy('id')->get()->map(
                static fn (object $row): array => [(int) $row->id, $row->username, $row->password]
            )->all(),
        );
    }

    public function test_a_second_row_of_a_login_is_refused_afterwards(): void
    {
        $alice = $this->makeUser('alice')->id;
        $this->migration()->up();
        $this->account($alice, 'alice_race', 'first');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->account($alice, 'alice_race', 'second');
    }

    private function account(int $userId, string $username, string $password): int
    {
        return (int) DB::table('sftp_accounts')->insertGetId([
            'user_id' => $userId,
            'username' => $username,
            'auth_method' => 'password',
            'password' => $password,
        ]);
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }
}
