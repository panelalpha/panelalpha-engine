<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\EnvVarOverrides;
use PHPUnit\Framework\TestCase;

/**
 * What a deploy's `env_vars` does to the overrides a project carries: null clears them, and anything unusable is ignored.
 */
class EnvVarOverridesIncomingTest extends TestCase
{
    public function test_null_clears_every_override(): void
    {
        $this->assertSame([], EnvVarOverrides::applyIncoming(null, ['APP_ENV' => 'prod']));
    }

    public function test_something_that_is_not_a_map_changes_nothing(): void
    {
        $this->assertSame(['APP_ENV' => 'prod'], EnvVarOverrides::applyIncoming('APP_ENV=dev', ['APP_ENV' => 'prod']));
    }

    public function test_invalid_names_and_non_scalar_values_are_ignored_and_null_removes(): void
    {
        $stored = ['APP_ENV' => 'prod', 'DEBUG' => '1'];

        $this->assertSame(
            ['APP_ENV' => 'prod', 'PORT' => '8080', 'NAME' => 'app'],
            EnvVarOverrides::applyIncoming([
                '1BAD' => 'x',
                'has-dash' => 'x',
                0 => 'x',
                'DEBUG' => null,
                'LIST' => ['a'],
                'PORT' => 8080,
                'NAME' => 'app',
            ], $stored)
        );
    }
}
