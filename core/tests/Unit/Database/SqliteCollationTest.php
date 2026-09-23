<?php

namespace Tests\Unit\Database;

use App\Models\BackupContainer;
use App\Models\Domain;
use App\Models\SftpAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

// Proves the NOCASE collation added to sqlite-only column definitions gives sqlite
// the same case-insensitive matching MySQL's utf8mb4_unicode_ci already gave these
// columns, for both lookups and unique constraints.
class SqliteCollationTest extends SqliteCollationTestCase
{
    public function test_username_lookup_matches_regardless_of_case(): void
    {
        User::create(['username' => 'foobar', 'domain' => 'foobar.example.com']);

        $found = User::findByUsername('FooBar');

        $this->assertNotNull($found);
        $this->assertSame('foobar', $found->username);
    }

    public function test_domain_lookup_matches_regardless_of_case(): void
    {
        $user = User::create(['username' => 'foobar', 'domain' => 'foobar.example.com']);
        Domain::create(['user_id' => $user->id, 'domain' => 'example.com', 'type' => 'main']);

        $found = Domain::findByName('EXAMPLE.com');

        $this->assertNotNull($found);
        $this->assertSame('example.com', $found->domain);
    }

    public function test_case_varied_duplicate_username_is_rejected_as_unique_violation(): void
    {
        User::create(['username' => 'foobar', 'domain' => 'foobar.example.com']);

        $this->expectException(UniqueConstraintViolationException::class);
        User::create(['username' => 'FooBar', 'domain' => 'other.example.com']);
    }

    public function test_case_varied_duplicate_backup_container_name_is_rejected_as_unique_violation(): void
    {
        BackupContainer::create(['name' => 'nightly', 'driver' => 'local', 'location' => '/backups']);

        $this->expectException(UniqueConstraintViolationException::class);
        BackupContainer::create(['name' => 'Nightly', 'driver' => 'local', 'location' => '/backups2']);
    }

    public function test_sftp_account_username_lookup_matches_regardless_of_case(): void
    {
        $user = User::create(['username' => 'foobar', 'domain' => 'foobar.example.com']);
        SftpAccount::create([
            'user_id' => $user->id,
            'username' => 'foobar_backup',
            'auth_method' => 'password',
        ]);

        $found = SftpAccount::query()->where('username', 'FOOBAR_BACKUP')->first();

        $this->assertNotNull($found);
    }
}
