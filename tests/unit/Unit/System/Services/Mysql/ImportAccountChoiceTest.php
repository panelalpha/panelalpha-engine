<?php

namespace Tests\Unit\System\Services\Mysql;

use App\System\Services\Mysql\ImportAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The account a copy gives every DEFINER to holds what the copied objects need to run, whatever its name. */
class ImportAccountChoiceTest extends TestCase
{
    private const RW = 'SELECT, INSERT, UPDATE, DELETE, EXECUTE, TRIGGER';

    /** @param  array<string, string>  $granted */
    #[DataProvider('grants')]
    public function test_the_chosen_account(array $granted, string $chosen): void
    {
        $this->assertSame($chosen, ImportAccount::choose('shop_multi', $granted)->user);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function grants(): iterable
    {
        yield 'a read-only account first by name loses to a writing one' => [['shop_ro' => 'SELECT', 'shop_rw' => 'ALL PRIVILEGES'], 'shop_rw'];
        yield 'a read-only account named like the database loses to a writing one' => [['shop_multi' => 'SELECT', 'shop_z' => self::RW], 'shop_z'];
        yield 'a narrow account without EXECUTE and TRIGGER loses to one that has them' => [['shop_a' => 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX', 'shop_b' => self::RW], 'shop_b'];
        yield 'of two capable accounts the most privileged' => [['shop_a' => self::RW, 'shop_b' => 'ALL PRIVILEGES'], 'shop_b'];
        yield 'of two capable accounts the one with more privileges' => [['shop_a' => self::RW, 'shop_b' => self::RW.', CREATE'], 'shop_b'];
        yield 'of equal accounts the one named like the database' => [['shop_a' => 'ALL PRIVILEGES', 'shop_multi' => 'ALL PRIVILEGES'], 'shop_multi'];
        yield 'of equal accounts not named like it the first by name' => [['shop_b' => 'ALL PRIVILEGES', 'shop_a' => 'ALL PRIVILEGES'], 'shop_a'];
        yield 'with none capable the most privileged anyway' => [['shop_a' => 'SELECT', 'shop_b' => 'SELECT, INSERT'], 'shop_b'];
        yield 'with none capable and equal, by name' => [['shop_b' => 'SELECT', 'shop_a' => 'SELECT'], 'shop_a'];
        yield 'privileges in any case and spacing' => [['shop_a' => 'SELECT', 'shop_b' => 'select,insert ,update,delete,execute,trigger'], 'shop_b'];
        yield 'none granted: a temporary account' => [[], ''];
    }
}
