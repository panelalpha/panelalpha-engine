<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\EnvVarDefault;
use PHPUnit\Framework\TestCase;

/**
 * Compose values written as shell expressions, resolved the way Compose
 * itself resolves them when nothing sets the variable.
 *
 * The engine reads a repo's compose file without a `.env` beside it, so
 * `${APP_PORT:-8090}:8000` has to become `8090:8000`. Leaving the expression
 * intact makes the whole mapping unparseable and the app unreachable.
 */
class EnvVarDefaultTest extends TestCase
{
    public function test_a_colon_dash_default_is_used(): void
    {
        $this->assertSame('8090:8000', EnvVarDefault::resolve('${APP_PORT:-8090}:8000'));
    }

    public function test_a_bare_dash_default_is_used(): void
    {
        $this->assertSame('8090:8000', EnvVarDefault::resolve('${APP_PORT-8090}:8000'));
    }

    public function test_a_variable_with_no_default_resolves_to_nothing(): void
    {
        // The caller's `> 0` guard then drops the mapping, which is right:
        // there is no way to know what port the operator meant.
        $this->assertSame(':8000', EnvVarDefault::resolve('${APP_PORT}:8000'));
    }

    public function test_an_unbraced_variable_resolves_to_nothing(): void
    {
        $this->assertSame(':8000', EnvVarDefault::resolve('$APP_PORT:8000'));
    }

    public function test_a_required_variable_resolves_to_nothing(): void
    {
        // The error message may hold colons; it must not leak into the split.
        $this->assertSame(':3000', EnvVarDefault::resolve('${WEB_SERVER_PORT:?WEB_SERVER_PORT variable missing}:3000'));
        $this->assertSame(':3000', EnvVarDefault::resolve('${WEB_SERVER_PORT?error: unset}:3000'));
    }

    public function test_several_variables_in_one_value_are_all_resolved(): void
    {
        $this->assertSame('127.0.0.1:8090:8000', EnvVarDefault::resolve('${HOST:-127.0.0.1}:${PORT:-8090}:8000'));
    }

    public function test_a_plain_value_is_left_alone(): void
    {
        $this->assertSame('8080:80/tcp', EnvVarDefault::resolve('8080:80/tcp'));
    }

    public function test_a_dollar_sign_that_names_nothing_is_left_alone(): void
    {
        $this->assertSame('$-8080', EnvVarDefault::resolve('$-8080'));
    }

    /**
     * Mailcow publishes its web ports as
     * `${HTTPS_BIND:-}:${HTTPS_PORT:-443}:${HTTPS_PORT:-443}` -- an empty
     * default meaning "no bind address unless you set one". The rule required
     * one character, so the bind half stayed unresolved, the mapping split
     * into four parts instead of three, and 80 and 443 were dropped. They are
     * the only two ports the engine can actually proxy, and it saw neither.
     */
    public function test_an_empty_default_resolves_to_nothing(): void
    {
        $this->assertSame(':443:443', EnvVarDefault::resolve('${HTTPS_BIND:-}:${HTTPS_PORT:-443}:${HTTPS_PORT:-443}'));
        $this->assertSame(':80:80', EnvVarDefault::resolve('${HTTP_BIND:-}:${HTTP_PORT:-80}:${HTTP_PORT:-80}'));
    }

    /** The same for the `-` form, which has no colon. */
    public function test_an_empty_default_resolves_for_the_dash_form(): void
    {
        $this->assertSame(':8080:80', EnvVarDefault::resolve('${VAR-}:8080:80'));
    }

    /** A default that is present is still taken, including one with colons. */
    public function test_a_present_default_is_unaffected(): void
    {
        $this->assertSame('25:25', EnvVarDefault::resolve('${SMTP_PORT:-25}:25'));
        $this->assertSame(
            '127.0.0.1:19991:12345',
            EnvVarDefault::resolve('${DOVEADM_PORT:-127.0.0.1:19991}:12345')
        );
    }
}
