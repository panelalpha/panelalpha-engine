<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootEngineComposeLines;
use App\System\Services\Mysql\DefinerMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SitesDbBrokerTest extends TestCase
{
    private const ENGINE = '/opt/panelalpha/shared-hosting';

    public function test_each_line_asks_for_the_action_with_the_values_the_broker_is_checked_against(): void
    {
        $lines = self::lines();
        $map = DefinerMap::map('alice_', 'bob_', ['alice_wp', 'alice_shop'], ['bob_wp', 'bob_shop']);
        $none = DefinerMap::map('', '', [], []);

        $this->assertSame([
            "/usr/local/lib/panelalpha/pa-root compose.sitesDbDump -- ".self::ENGINE." alice_wp ''",
            "/usr/local/lib/panelalpha/pa-root compose.sitesDbDump -- ".self::ENGINE." alice-wp 'wp_\$posts'",
            '/usr/local/lib/panelalpha/pa-root compose.sitesDbRestore -- '.self::ENGINE.' bob_wp',
            '/usr/local/lib/panelalpha/pa-root compose.sitesDbCopy -- '.self::ENGINE.' alice_wp bob_wp \'\' bob_no.definer 2 alice_wp bob_wp alice_shop bob_shop',
            '/usr/local/lib/panelalpha/pa-root compose.sitesDbCopy -- '.self::ENGINE.' alice-wp bob-wp wp_posts no.definer 0',
            '/usr/local/lib/panelalpha/pa-root compose.sitesDbDropTable -- '.self::ENGINE.' bob_wp wp_posts',
        ], [
            $lines->sitesDbDump('alice_wp', null),
            $lines->sitesDbDump('alice-wp', 'wp_$posts'),
            $lines->sitesDbRestore('bob_wp'),
            $lines->sitesDbCopy('alice_wp', 'bob_wp', null, $map->orphan(), $map->accounts()),
            $lines->sitesDbCopy('alice-wp', 'bob-wp', 'wp_posts', $none->orphan(), $none->accounts()),
            $lines->sitesDbDropTable('bob_wp', 'wp_posts'),
        ]);
    }

    public function test_the_definers_travel_as_a_counted_list_after_the_orphan(): void
    {
        $this->assertSame(
            "/usr/local/lib/panelalpha/pa-root compose.sitesDbCopy -- /opt/panelalpha/shared-hosting a_x b_x '' b_no.definer 1 a_u b_u",
            self::lines()->sitesDbCopy('a_x', 'b_x', null, 'b_no.definer', ['a_u' => 'b_u']),
        );
    }

    /** @return iterable<string, array{\Closure(RootEngineComposeLines): string}> */
    public static function refused(): iterable
    {
        yield 'database with a quote' => [fn (RootEngineComposeLines $l) => $l->sitesDbDump("a'b", null)];
        yield 'database with a space' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestore('a b')];
        yield 'empty database' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestore('')];
        yield 'table with a backtick' => [fn (RootEngineComposeLines $l) => $l->sitesDbDropTable('a', 't`; DROP TABLE x')];
        yield 'table with a line break' => [fn (RootEngineComposeLines $l) => $l->sitesDbDump('a', "t\n")];
        yield 'definer with a backtick' => [fn (RootEngineComposeLines $l) => $l->sitesDbCopy('a', 'b', null, 'no.definer', ['a_u' => 'b`u'])];
        yield 'source user with a quote' => [fn (RootEngineComposeLines $l) => $l->sitesDbCopy('a', 'b', null, 'no.definer', ["a'u" => 'b_u'])];
        yield 'orphan that is root' => [fn (RootEngineComposeLines $l) => $l->sitesDbCopy('a', 'b', null, 'root', [])];
        yield 'relative project directory' => [fn (RootEngineComposeLines $l) => Privileged::engineComposeLines('opt/engine')->sitesDbRestore('a')];
        yield 'project directory climbing out' => [fn (RootEngineComposeLines $l) => Privileged::engineComposeLines('/opt/engine/..')->sitesDbRestore('a')];
    }

    /** @param  \Closure(RootEngineComposeLines): string  $build */
    #[DataProvider('refused')]
    public function test_a_name_the_script_could_misread_is_refused_before_the_broker_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(self::lines());
    }

    private static function lines(): RootEngineComposeLines
    {
        return Privileged::engineComposeLines(self::ENGINE);
    }
}
