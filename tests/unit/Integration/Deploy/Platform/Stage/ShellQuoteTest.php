<?php

namespace Tests\Integration\Deploy\Platform\Stage;

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

    /** What sh itself would hand the command, so the assertions are about behaviour. */
    private function unquote(string $quoted): string
    {
        return (string) shell_exec('printf %s ' . $quoted);
    }
}
