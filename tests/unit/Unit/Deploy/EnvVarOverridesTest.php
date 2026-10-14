<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\EnvVarOverrides;
use PHPUnit\Framework\TestCase;

/**
 * The overrides survive a deploy that did not mention them.
 *
 * The case this exists for: a Symfony project was given an APP_SECRET on one
 * deploy, and a later rebuild sending only `{"APP_ENV":"prod"}` replaced the
 * stored map outright — the application came back up on an empty secret and
 * answered 500 on "A non-empty secret is required".
 */
class EnvVarOverridesTest extends TestCase
{
    public function test_a_deploy_that_names_one_variable_keeps_the_rest(): void
    {
        $this->assertSame(
            ['APP_SECRET' => 'kept', 'DEFAULT_URI' => 'https://app.test/', 'APP_ENV' => 'prod'],
            EnvVarOverrides::merge(
                ['APP_SECRET' => 'kept', 'DEFAULT_URI' => 'https://app.test/'],
                ['APP_ENV' => 'prod']
            )
        );
    }

    public function test_a_named_variable_is_replaced_not_appended(): void
    {
        $this->assertSame(
            ['APP_ENV' => 'prod'],
            EnvVarOverrides::merge(['APP_ENV' => 'production'], ['APP_ENV' => 'prod'])
        );
    }

    /**
     * An empty override is no override everywhere these values are read, so
     * storing one as a variable that does nothing would only be a way to never
     * be rid of it.
     */
    public function test_an_empty_value_removes_the_key(): void
    {
        $this->assertSame(
            ['APP_ENV' => 'prod'],
            EnvVarOverrides::merge(
                ['APP_ENV' => 'prod', 'DEFAULT_URI' => 'https://app.test/'],
                ['DEFAULT_URI' => '']
            )
        );
    }

    public function test_removing_a_key_the_project_never_had_is_not_an_error(): void
    {
        $this->assertSame(
            ['APP_ENV' => 'prod'],
            EnvVarOverrides::merge(['APP_ENV' => 'prod'], ['NEVER_SET' => ''])
        );
    }

    public function test_an_empty_value_on_the_first_deploy_is_not_stored(): void
    {
        $this->assertSame(
            ['APP_ENV' => 'prod'],
            EnvVarOverrides::merge([], ['APP_ENV' => 'prod', 'APP_SECRET' => ''])
        );
    }

    public function test_a_deploy_that_declared_nothing_changes_nothing(): void
    {
        $stored = ['APP_ENV' => 'prod', 'APP_SECRET' => 'kept'];

        $this->assertSame($stored, EnvVarOverrides::merge($stored, []));
    }

    /**
     * "0" and "false" are values a project means, and only the empty string
     * says "not set" — the same line ProjectEnvironment and ComposeEnvironment
     * draw.
     */
    public function test_zero_is_a_value_not_a_removal(): void
    {
        $this->assertSame(
            ['APP_DEBUG' => '0', 'FEATURE' => 'false'],
            EnvVarOverrides::merge(['APP_DEBUG' => '1'], ['APP_DEBUG' => '0', 'FEATURE' => 'false'])
        );
    }
}
