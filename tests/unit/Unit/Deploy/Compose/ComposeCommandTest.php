<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeCommand;
use PHPUnit\Framework\TestCase;

/**
 * A service's `command:` as one string, whichever of Compose's two forms the
 * project wrote it in.
 *
 * Both spellings are idiomatic and repos use both, so anything that wants to
 * read what a service runs - to spot a dev server, or a migration - has to
 * see the same thing either way.
 */
class ComposeCommandTest extends TestCase
{
    public function test_the_shell_form_is_returned_as_written(): void
    {
        $this->assertSame('npm run dev', ComposeCommand::asString('npm run dev'));
    }

    public function test_the_list_form_is_joined(): void
    {
        $this->assertSame('npm run dev', ComposeCommand::asString(['npm', 'run', 'dev']));
    }

    public function test_a_numeric_argument_is_kept(): void
    {
        // `["php", "-S", "0.0.0.0:8080", "-t", 8080]` - YAML makes the bare
        // number an int, and dropping it would change the command.
        $this->assertSame('redis-server --port 6379', ComposeCommand::asString(['redis-server', '--port', 6379]));
    }

    public function test_entries_that_are_not_arguments_are_dropped(): void
    {
        $this->assertSame('npm run', ComposeCommand::asString(['npm', null, 'run', ['nested']]));
    }

    public function test_a_service_that_declares_no_command_yields_nothing(): void
    {
        $this->assertSame('', ComposeCommand::asString(null));
        $this->assertSame('', ComposeCommand::asString([]));
    }
}
