<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\MysqlSidecar;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** engine#189: the Laravel sidecar's user and root shared `app`, or root had none. */
class MysqlSidecarPasswordTest extends TestCase
{
    /** @return array<string, string> */
    private function db(string $username, string $password): array
    {
        return [
            'connection' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'laravel',
            'username' => $username,
            'password' => $password,
        ];
    }

    public function test_a_blank_user_password_is_filled_and_root_gets_another(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');
        $db = MysqlSidecar::withPassword($this->db('forge', ''), $passwords);
        $env = MysqlSidecar::service($db, $passwords)['environment'];

        // PhpEnvironment hands the app $db['password'], so both ends agree.
        $this->assertSame($passwords->for('MYSQL_PASSWORD'), $db['password']);
        $this->assertSame($db['password'], $env['MYSQL_PASSWORD']);
        $this->assertSame($passwords->for('MYSQL_ROOT_PASSWORD'), $env['MYSQL_ROOT_PASSWORD']);
        $this->assertNotSame($env['MYSQL_PASSWORD'], $env['MYSQL_ROOT_PASSWORD']);
    }

    public function test_a_root_account_with_no_password_is_no_longer_left_open(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');
        $db = MysqlSidecar::withPassword($this->db('root', ''), $passwords);
        $env = MysqlSidecar::service($db, $passwords)['environment'];

        $this->assertArrayNotHasKey('MYSQL_ALLOW_EMPTY_PASSWORD', $env);
        $this->assertSame($passwords->for('MYSQL_ROOT_PASSWORD'), $env['MYSQL_ROOT_PASSWORD']);
        $this->assertSame($env['MYSQL_ROOT_PASSWORD'], $db['password']);
    }

    public function test_a_password_the_project_set_is_kept_and_root_no_longer_shares_it(): void
    {
        $passwords = SidecarPasswords::derived('account-seed');
        $db = MysqlSidecar::withPassword($this->db('forge', 's3cret'), $passwords);
        $env = MysqlSidecar::service($db, $passwords)['environment'];

        $this->assertSame('s3cret', $env['MYSQL_PASSWORD']);
        $this->assertNotSame('s3cret', $env['MYSQL_ROOT_PASSWORD']);
    }

    public function test_a_legacy_account_gets_exactly_what_it_was_initialised_with(): void
    {
        $legacy = SidecarPasswords::legacy();

        $user = MysqlSidecar::service(MysqlSidecar::withPassword($this->db('forge', ''), $legacy), $legacy)['environment'];
        $root = MysqlSidecar::service(MysqlSidecar::withPassword($this->db('root', ''), $legacy), $legacy)['environment'];

        $this->assertSame('app', $user['MYSQL_PASSWORD']);
        $this->assertSame('app', $user['MYSQL_ROOT_PASSWORD']);
        $this->assertSame('yes', $root['MYSQL_ALLOW_EMPTY_PASSWORD']);
        // No passwords object at all is the legacy behaviour, for callers that pass none.
        $this->assertSame($user, MysqlSidecar::service($this->db('forge', ''))['environment']);
    }

    /**
     * withSidecar() restates DB_PASSWORD over the `.env.example` (engine#288):
     * it has to be the password the sidecar was started with, in both modes.
     */
    #[DataProvider('modes')]
    public function test_the_restated_connection_matches_the_sidecar(SidecarPasswords $passwords, string $user): void
    {
        $db = MysqlSidecar::withPassword($this->db($user, ''), $passwords);
        $decision = MysqlSidecar::withSidecar(['env' => ['DB_HOST' => 'db', 'DB_PASSWORD' => 'secret']], $db, $passwords);
        $sidecar = $decision['sidecars']['db']['environment'];

        $expected = $user === 'root'
            ? ($sidecar['MYSQL_ROOT_PASSWORD'] ?? '')
            : $sidecar['MYSQL_PASSWORD'];
        $this->assertSame($expected, $decision['env']['DB_PASSWORD']);
        $this->assertSame('127.0.0.1', $decision['env']['DB_HOST']);
    }

    /** @return array<string, array{SidecarPasswords, string}> */
    public static function modes(): array
    {
        return [
            'derived user' => [SidecarPasswords::derived('account-seed'), 'forge'],
            'derived root' => [SidecarPasswords::derived('account-seed'), 'root'],
            'legacy user' => [SidecarPasswords::legacy(), 'forge'],
            'legacy root' => [SidecarPasswords::legacy(), 'root'],
        ];
    }
}
