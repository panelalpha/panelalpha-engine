<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Editing a service's `environment:` without changing how it is written.
 *
 * Compose takes a map or a list of KEY=value lines, and the customer's own
 * compose file is the one being edited: rewriting a list as a map produces a
 * diff of the whole block for the sake of one added line.
 */
class ServiceEnvironmentTest extends TestCase
{
    public function test_the_map_form_is_recognised_and_kept(): void
    {
        $env = ServiceEnvironment::withDefaults(
            ['APP_ENV' => 'production'],
            ['APP_DEBUG' => 'false']
        );

        $this->assertSame(['APP_ENV' => 'production', 'APP_DEBUG' => 'false'], $env);
    }

    public function test_the_list_form_is_recognised_and_kept(): void
    {
        $env = ServiceEnvironment::withDefaults(['APP_ENV=production'], ['APP_DEBUG' => 'false']);

        $this->assertSame(['APP_ENV=production', 'APP_DEBUG=false'], $env);
    }

    public function test_a_value_the_project_set_is_never_overwritten(): void
    {
        // These are defaults. A project that already says what it wants keeps
        // saying it, in either form.
        $this->assertSame(
            ['APP_ENV' => 'staging'],
            ServiceEnvironment::withDefaults(['APP_ENV' => 'staging'], ['APP_ENV' => 'production'])
        );
        $this->assertSame(
            ['APP_ENV=staging'],
            ServiceEnvironment::withDefaults(['APP_ENV=staging'], ['APP_ENV' => 'production'])
        );
    }

    public function test_an_absent_environment_becomes_the_defaults(): void
    {
        $this->assertSame(['APP_ENV' => 'production'], ServiceEnvironment::withDefaults(null, ['APP_ENV' => 'production']));
        $this->assertSame(['APP_ENV' => 'production'], ServiceEnvironment::withDefaults([], ['APP_ENV' => 'production']));
    }

    public function test_no_defaults_leaves_the_environment_alone(): void
    {
        $this->assertSame(['APP_ENV=production'], ServiceEnvironment::withDefaults(['APP_ENV=production']));
    }

    public function test_a_pass_through_line_does_not_hide_a_default(): void
    {
        // `- APP_ENV` with no `=` forwards the host's variable, which is not
        // set in an account. The default has to be appended anyway.
        $env = ServiceEnvironment::withDefaults(['APP_DEBUG=false', 'APP_ENV'], ['APP_ENV' => 'production']);

        $this->assertSame(['APP_DEBUG=false', 'APP_ENV', 'APP_ENV=production'], $env);
    }

    public function test_a_key_is_found_in_either_form(): void
    {
        $this->assertTrue(ServiceEnvironment::hasKey(['APP_ENV' => 'production'], 'APP_ENV'));
        $this->assertTrue(ServiceEnvironment::hasKey(['APP_ENV=production'], 'APP_ENV'));
    }

    public function test_a_pass_through_line_does_not_count_as_a_value(): void
    {
        // `- APP_ENV` forwards the host's variable, and nothing sets it in an
        // account. The only caller asks in order to decide whether to supply
        // a value, so an unset pass-through has to read as absent.
        $this->assertFalse(ServiceEnvironment::hasKey(['APP_ENV'], 'APP_ENV'));
    }

    public function test_a_key_that_is_not_there_is_reported_missing(): void
    {
        $this->assertFalse(ServiceEnvironment::hasKey(['APP_ENV' => 'production'], 'APP_DEBUG'));
        $this->assertFalse(ServiceEnvironment::hasKey(null, 'APP_ENV'));
        $this->assertFalse(ServiceEnvironment::hasKey('APP_ENV=production', 'APP_ENV'));
    }

    public function test_the_form_is_decided_by_the_shape(): void
    {
        $this->assertTrue(ServiceEnvironment::isList(['APP_ENV=production']));
        $this->assertFalse(ServiceEnvironment::isList(['APP_ENV' => 'production']));
        // Neither form; the caller that cares merges defaults as a map.
        $this->assertFalse(ServiceEnvironment::isList([]));
    }

    /**
     * GoatCounter's, verbatim. A map with one entry whose *value* contains an
     * `=` was read as a list, so the caller appended with `$environment[] =`
     * onto an associative array, Yaml::dump emitted an integer key, and
     * compose refused the file the engine had just written over the
     * customer's own:
     *
     *     non-string key in services.goatcounter-postgres.environment: 0
     */
    public function test_a_map_whose_value_contains_an_equals_is_not_a_list(): void
    {
        $environment = [
            'GOATCOUNTER_DB' => 'postgresql+postgresql://goatcounter:goatcounter@postgres:5432/goatcounter?sslmode=disable',
        ];

        $this->assertFalse(ServiceEnvironment::isList($environment));

        $merged = ServiceEnvironment::withDefaults($environment, ['NODE_OPTIONS' => '--max-old-space-size=512']);

        $this->assertSame(
            ['GOATCOUNTER_DB', 'NODE_OPTIONS'],
            array_keys($merged),
            'the merged environment must stay a map, with no integer keys'
        );
        foreach (array_keys($merged) as $key) {
            $this->assertIsString($key);
        }
    }

    /**
     * The other direction: a list whose first entry is a pass-through with no
     * `=` was read as a map and corrupted the same way.
     */
    public function test_a_list_starting_with_a_pass_through_is_still_a_list(): void
    {
        $environment = ['POSTGRES_PASSWORD', 'APP_ENV=production'];

        $this->assertTrue(ServiceEnvironment::isList($environment));

        $merged = ServiceEnvironment::withDefaults($environment, ['TZ' => 'UTC']);

        $this->assertSame(['POSTGRES_PASSWORD', 'APP_ENV=production', 'TZ=UTC'], $merged);
    }
}
