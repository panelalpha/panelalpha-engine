<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeValues;
use PHPUnit\Framework\TestCase;

/**
 * Values on their way into a generated compose file.
 *
 * A deploy decision crosses a process boundary as JSON, so a port that was an
 * int arrives as one and a missing entry arrives as null. Compose is
 * unforgiving about both, and the generator is the wrong place to find out:
 * the error surfaces as a YAML parse failure with no line anyone can act on.
 */
class ComposeValuesTest extends TestCase
{
    public function test_a_map_of_strings_survives(): void
    {
        $this->assertSame(
            ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'],
            ComposeValues::stringMap(['APP_ENV' => 'production', 'APP_DEBUG' => 'false'])
        );
    }

    public function test_an_integer_value_becomes_a_string(): void
    {
        // `PORT: 8080` is a YAML integer, and Compose wants an environment
        // value to be a string.
        $this->assertSame(['PORT' => '8080'], ComposeValues::stringMap(['PORT' => 8080]));
    }

    public function test_values_compose_cannot_use_are_dropped(): void
    {
        $this->assertSame([], ComposeValues::stringMap([
            'NOTHING' => null,
            'NESTED' => ['a' => 'b'],
            'FLOATY' => 1.5,
            'FLAG' => true,
        ]));
    }

    public function test_keys_compose_cannot_use_are_dropped(): void
    {
        // A JSON array arriving where an object was meant gives integer keys.
        $this->assertSame([], ComposeValues::stringMap([0 => 'production', '' => 'x']));
    }

    public function test_anything_that_is_not_a_map_yields_an_empty_map(): void
    {
        $this->assertSame([], ComposeValues::stringMap(null));
        $this->assertSame([], ComposeValues::stringMap('APP_ENV=production'));
    }

    public function test_a_list_of_strings_survives_and_is_reindexed(): void
    {
        // Gaps left by filtering would serialise as a YAML map, not a list.
        $this->assertSame(
            ['db', 'redis'],
            ComposeValues::stringList([0 => 'db', 5 => 'redis'])
        );
    }

    public function test_empty_and_non_string_entries_are_dropped(): void
    {
        $this->assertSame(['db'], ComposeValues::stringList(['db', '', null, 8080, ['nested']]));
    }

    public function test_anything_that_is_not_a_list_yields_an_empty_list(): void
    {
        $this->assertSame([], ComposeValues::stringList(null));
        $this->assertSame([], ComposeValues::stringList('db'));
    }
}
