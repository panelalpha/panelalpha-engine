<?php

namespace Tests\Unit\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\Stage\ShellQuote;
use PHPUnit\Framework\TestCase;

/**
 * Single-quoting for the entrypoint.
 *
 * Everything the engine interpolates into that script came from a manifest
 * or from a project's own package.json, and it is written into a file that
 * runs as the container's PID 1. A value that closes its own quote does not
 * produce a syntax error; it produces a command.
 */
class ShellQuoteTest extends TestCase
{
    public function test_an_ordinary_value_is_wrapped(): void
    {
        $this->assertSame("'npm run build'", ShellQuote::of('npm run build'));
    }

    public function test_a_value_containing_a_quote_cannot_escape_it(): void
    {
        // The standard sh idiom: close, escaped quote, reopen. The result is
        // still one argument.
        $this->assertSame("'it'\\''s'", ShellQuote::of("it's"));
    }

    public function test_an_injection_attempt_stays_one_argument(): void
    {
        $quoted = ShellQuote::of("'; rm -rf /; echo '");

        $this->assertSame("''\\''; rm -rf /; echo '\\'''", $quoted);
        $this->assertSame("'; rm -rf /; echo '", $this->unquote($quoted));
    }

    public function test_shell_metacharacters_are_inert(): void
    {
        foreach (['$HOME', '`id`', '$(id)', 'a && b', 'a | b', 'a > b', '*'] as $value) {
            $this->assertSame($value, $this->unquote(ShellQuote::of($value)), $value);
        }
    }

    public function test_an_empty_value_is_still_an_argument(): void
    {
        $this->assertSame("''", ShellQuote::of(''));
    }

    /** What sh itself would hand the command, so the assertions are about behaviour. */
    private function unquote(string $quoted): string
    {
        return (string) shell_exec('printf %s ' . $quoted);
    }
}
