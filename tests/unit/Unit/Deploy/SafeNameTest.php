<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\SafeName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Names about to become part of a filesystem path or a command's argv.
 *
 * An account username reaches the engine from the database and ends up in a
 * log directory, a cache directory, a Docker volume name and several sudo
 * commands. The rule lives in one place so the two holes the scattered copies
 * shared cannot come back: PHP's `$` matching before a trailing newline, and
 * `..` being made entirely of accepted characters.
 */
class SafeNameTest extends TestCase
{
    public function test_ordinary_account_names_are_accepted(): void
    {
        foreach (['acme', 'acme-shop', 'acme_shop', 'acme.shop', 'a1', 'dep_20260828_1'] as $name) {
            $this->assertTrue(SafeName::isSafe($name), $name);
        }
    }

    public function test_a_trailing_newline_is_refused(): void
    {
        // The reason the pattern ends in \z. With `$` this passed, and the
        // name went on to be interpolated into a path and a sudo argv.
        $this->assertFalse(SafeName::isSafe("acme\n"));
        $this->assertFalse(SafeName::isSafe("acme\nrm -rf /"));
    }

    public function test_a_name_of_only_dots_is_refused(): void
    {
        // Every character is accepted individually, and the result resolves
        // to the parent of the directory it was supposed to name.
        $this->assertFalse(SafeName::isSafe('.'));
        $this->assertFalse(SafeName::isSafe('..'));
        $this->assertFalse(SafeName::isSafe('...'));
    }

    public function test_a_dot_inside_a_name_is_still_fine(): void
    {
        $this->assertTrue(SafeName::isSafe('..acme'));
        $this->assertTrue(SafeName::isSafe('acme..shop'));
    }

    public function test_a_separator_is_refused(): void
    {
        foreach (['acme/shop', '/etc/passwd', 'acme\\shop', '../other'] as $name) {
            $this->assertFalse(SafeName::isSafe($name), $name);
        }
    }

    public function test_shell_metacharacters_are_refused(): void
    {
        foreach (['acme;id', 'acme id', 'acme$(id)', 'acme`id`', 'acme|id', 'acme&'] as $name) {
            $this->assertFalse(SafeName::isSafe($name), $name);
        }
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->assertFalse(SafeName::isSafe(''));
    }

    public function test_the_assertion_names_what_it_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid username for build cache: ../other');

        SafeName::assert('../other', 'username for build cache');
    }

    public function test_the_assertion_passes_a_good_name_through(): void
    {
        SafeName::assert('acme', 'account username');

        $this->addToAssertionCount(1);
    }
}
