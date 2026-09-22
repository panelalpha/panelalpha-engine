<?php

namespace Tests\Unit\Deploy\Checkout;

use App\Lib\Deploy\Checkout\CheckoutExclude;
use PHPUnit\Framework\TestCase;

/**
 * The block of Engine Artifacts the engine keeps in a checkout's local exclude
 * file (ADR-0001): replaced in place, appended when absent, and never a line
 * the client wrote.
 */
class CheckoutExcludeTest extends TestCase
{
    public function test_block_is_appended_to_a_file_without_one(): void
    {
        $existing = "# git ls-files --others --exclude-from=.git/info/exclude\n*.log\n";

        $merged = CheckoutExclude::merge($existing, CheckoutExclude::render(['/panelalpha.Dockerfile', '/.env']));

        $this->assertSame(
            "# git ls-files --others --exclude-from=.git/info/exclude\n"
            . "*.log\n"
            . "\n"
            . "# >>> panelalpha-engine\n"
            . "# Engine Artifacts, rewritten by PanelAlpha Engine on every deploy. Edits here are lost.\n"
            . "/panelalpha.Dockerfile\n"
            . "/.env\n"
            . "# <<< panelalpha-engine\n",
            $merged
        );
    }

    public function test_block_is_the_whole_file_when_the_file_is_empty(): void
    {
        $merged = CheckoutExclude::merge('', CheckoutExclude::render(['/.env']));

        $this->assertSame(
            "# >>> panelalpha-engine\n"
            . "# Engine Artifacts, rewritten by PanelAlpha Engine on every deploy. Edits here are lost.\n"
            . "/.env\n"
            . "# <<< panelalpha-engine\n",
            $merged
        );
    }

    public function test_existing_block_is_replaced_in_place_and_client_lines_around_it_are_kept(): void
    {
        $existing = "*.log\n"
            . "# >>> panelalpha-engine\n"
            . "/old-artifact\n"
            . "# <<< panelalpha-engine\n"
            . "/my-notes.txt\n";

        $merged = CheckoutExclude::merge($existing, CheckoutExclude::render(['/.env']));

        $this->assertSame(
            "*.log\n"
            . "# >>> panelalpha-engine\n"
            . "# Engine Artifacts, rewritten by PanelAlpha Engine on every deploy. Edits here are lost.\n"
            . "/.env\n"
            . "# <<< panelalpha-engine\n"
            . "/my-notes.txt\n",
            $merged
        );
    }

    public function test_client_file_without_trailing_newline_keeps_its_last_line(): void
    {
        $merged = CheckoutExclude::merge('*.log', CheckoutExclude::render(['/.env']));

        $this->assertStringStartsWith("*.log\n\n# >>> panelalpha-engine\n", $merged);
    }

    public function test_merging_the_same_block_twice_yields_an_identical_file(): void
    {
        $block = CheckoutExclude::render(['/panelalpha.Dockerfile', '/.env', '/api/.env']);

        foreach (['', "*.log\n", '*.log', "*.log\n# >>> panelalpha-engine\n/x\n# <<< panelalpha-engine\n/y\n"] as $existing) {
            $once = CheckoutExclude::merge($existing, $block);

            $this->assertSame($once, CheckoutExclude::merge($once, $block));
        }
    }

    public function test_render_lists_each_pattern_once_in_the_order_given(): void
    {
        $block = CheckoutExclude::render(['/.env', '/.dockerignore', '/.env']);

        $this->assertSame(
            "# >>> panelalpha-engine\n"
            . "# Engine Artifacts, rewritten by PanelAlpha Engine on every deploy. Edits here are lost.\n"
            . "/.env\n"
            . "/.dockerignore\n"
            . "# <<< panelalpha-engine\n",
            $block
        );
    }

    public function test_anchored_path_escapes_characters_git_reads_as_pattern_syntax(): void
    {
        $this->assertSame('/api/.env', CheckoutExclude::anchoredPath('api/.env'));
        $this->assertSame('/weird\\[dir\\]/\\*\\?/.env', CheckoutExclude::anchoredPath('weird[dir]/*?/.env'));
        $this->assertSame('/back\\\\slash/.env', CheckoutExclude::anchoredPath('back\\slash/.env'));
        // Leading `#` and `!` only mean something at the start of a line, and the slash comes first.
        $this->assertSame('/#notes/!important/.env', CheckoutExclude::anchoredPath('#notes/!important/.env'));
    }
}
