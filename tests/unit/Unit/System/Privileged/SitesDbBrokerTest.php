<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootEngineComposeLines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SitesDbBrokerTest extends TestCase
{
    private const ENGINE = '/opt/panelalpha/shared-hosting';

    public function test_each_line_asks_for_the_action_with_the_values_the_broker_is_checked_against(): void
    {
        $lines = self::lines();
        $client = '/usr/local/lib/panelalpha/pa-root';

        $this->assertSame([
            "{$client} compose.sitesDbDump -- ".self::ENGINE." alice_wp ''",
            "{$client} compose.sitesDbDump -- ".self::ENGINE." alice-wp 'wp_\$posts'",
            "{$client} compose.sitesDbRestore -- ".self::ENGINE.' bob_wp',
            "{$client} compose.sitesDbCopy -- ".self::ENGINE." alice_wp bob_wp '' bob_app",
            "{$client} compose.sitesDbCopy -- ".self::ENGINE.' alice-wp bob-wp wp_posts bob_app',
            "{$client} compose.sitesDbRestoreCopy -- ".self::ENGINE.' alice_wp bob_wp bob_app',
            "{$client} compose.sitesDbCopy -- ".self::ENGINE." alice_wp bob_wp '' ''",
            "{$client} compose.sitesDbDropTable -- ".self::ENGINE.' bob_wp wp_posts',
        ], [
            $lines->sitesDbDump('alice_wp', null),
            $lines->sitesDbDump('alice-wp', 'wp_$posts'),
            $lines->sitesDbRestore('bob_wp'),
            $lines->sitesDbCopy('alice_wp', 'bob_wp', null, 'bob_app'),
            $lines->sitesDbCopy('alice-wp', 'bob-wp', 'wp_posts', 'bob_app'),
            $lines->sitesDbRestoreCopy('alice_wp', 'bob_wp', 'bob_app'),
            $lines->sitesDbCopy('alice_wp', 'bob_wp', null, ''),
            $lines->sitesDbDropTable('bob_wp', 'wp_posts'),
        ]);
    }

    /** @return iterable<string, array{\Closure(RootEngineComposeLines): string}> */
    public static function refused(): iterable
    {
        yield 'database with a quote' => [fn (RootEngineComposeLines $l) => $l->sitesDbDump("a'b", null)];
        yield 'database with a space' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestore('a b')];
        yield 'empty database' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestore('')];
        yield 'table with a backtick' => [fn (RootEngineComposeLines $l) => $l->sitesDbDropTable('a', 't`; DROP TABLE x')];
        yield 'table with a line break' => [fn (RootEngineComposeLines $l) => $l->sitesDbDump('a', "t\n")];
        yield 'import account with a backtick' => [fn (RootEngineComposeLines $l) => $l->sitesDbCopy('a', 'b', null, 'b`u')];
        yield 'import account with a quote' => [fn (RootEngineComposeLines $l) => $l->sitesDbCopy('a', 'b', null, "b'u")];
        yield 'import account that is root' => [fn (RootEngineComposeLines $l) => $l->sitesDbCopy('a', 'b', null, 'root')];
        yield 'restored copy into a database with a quote' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestoreCopy('a', "b'", 'b_u')];
        yield 'restored copy import account with a backtick' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestoreCopy('a', 'b', 'b`u')];
        yield 'restored copy import account that is root' => [fn (RootEngineComposeLines $l) => $l->sitesDbRestoreCopy('a', 'b', 'Root')];
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
