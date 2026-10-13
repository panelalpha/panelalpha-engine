<?php

namespace Tests\Unit\System;

use App\System\Services\Mysql\DefinerMap;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DefinerMapTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_source_user_maps_only_to_a_user_the_destination_owns(): void
    {
        $map = DefinerMap::map('alice_', 'bob_', ['alice_wp', 'alice_shop'], ['bob_wp']);

        $this->assertSame(['alice_wp' => 'bob_wp'], $map->accounts());
        $this->assertSame('bob_no.definer', $map->orphan());
    }

    public function test_a_rename_keeps_the_project_users_as_they_are(): void
    {
        $map = DefinerMap::map('shop_', 'shop_', ['shop_user'], ['shop_user']);

        $this->assertSame(['shop_user' => 'shop_user'], $map->accounts());
    }

    public function test_a_name_outside_the_grammar_is_never_mapped(): void
    {
        $map = DefinerMap::map('alice_', 'bob_', ["alice_x'y", 'alice_a`b'], ["bob_x'y", 'bob_a`b']);

        $this->assertSame([], $map->accounts());
    }

    public function test_a_prefix_outside_the_grammar_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid MySQL prefix');

        DefinerMap::map('alice_', "bob'_", [], []);
    }

    public function test_the_catch_all_comes_after_every_mapped_user(): void
    {
        $expressions = DefinerMap::map('alice_', 'bob_', ['alice_wp'], ['bob_wp'])->sedExpressions();

        $this->assertSame('s/DEFINER=`alice_wp`@`%`/DEFINER\n`bob_wp`@`%`/g', $expressions[0]);
        $this->assertStringEndsWith('/DEFINER=`bob_no.definer`@`%`/g', $expressions[1]);
        $this->assertSame('s/DEFINER\n/DEFINER=/g', $expressions[2]);
    }

    public function test_between_reads_both_projects_users(): void
    {
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $alice->mysqlUsers()->create(['user' => 'alice_wp']);
        $alice->mysqlUsers()->create(['user' => 'alice_shop']);
        $bob = $this->makeUser('bob', ['mysql_prefix' => 'bob_']);
        $bob->mysqlUsers()->create(['user' => 'bob_wp']);

        $map = DefinerMap::between($alice, $bob);

        $this->assertSame(['alice_wp' => 'bob_wp'], $map->accounts());
    }

    public function test_a_project_copy_maps_every_source_user_it_will_create(): void
    {
        $alice = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $alice->mysqlUsers()->create(['user' => 'alice_wp']);
        $alice->mysqlUsers()->create(['user' => 'alice_shop']);
        $bob = $this->makeUser('bob', ['mysql_prefix' => 'bob_']);

        $map = DefinerMap::forProjectCopy($alice, $bob);

        $this->assertSame(['alice_wp' => 'bob_wp', 'alice_shop' => 'bob_shop'], $map->accounts());
    }
}
