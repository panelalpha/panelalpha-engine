<?php

namespace Tests\Unit\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayDirectives;
use App\Lib\Overlay\OverlayPosition;
use PHPUnit\Framework\TestCase;

class OverlayDirectivesTest extends TestCase
{
    public function test_no_overlay_renders_nothing(): void
    {
        $this->assertSame(['overlay_enabled' => false], OverlayDirectives::templateVars([]));
    }

    public function test_an_overlay_goes_before_the_closing_body_tag_of_an_uncompressed_answer(): void
    {
        $vars = OverlayDirectives::templateVars([new Overlay('<div id="pa-banner">Hi</div>')]);

        $this->assertTrue($vars['overlay_enabled']);
        $this->assertSame('', $vars['overlay_http']);
        $this->assertSame(
            "proxy_set_header Accept-Encoding \"\";\n"
            ."sub_filter_once on;\n"
            ."sub_filter '</body>' '<div id=\"pa-banner\">Hi</div></body>';",
            $vars['overlay_location'],
        );
    }

    public function test_quotes_and_backslashes_are_escaped_for_nginx(): void
    {
        $this->assertSame("'it\\'s a \\\\n'", OverlayDirectives::quoted("it's a \\n"));
    }

    public function test_a_dollar_is_written_as_the_dollar_variable_the_vhost_defines(): void
    {
        $vars = OverlayDirectives::templateVars([new Overlay('<script>const $x = `${a}`;</script>')]);

        $this->assertStringContainsString(
            "'<script>const \${pa_overlay_dollar}x = `\${pa_overlay_dollar}{a}`;</script></body>'",
            $vars['overlay_location'],
        );
        $this->assertStringStartsWith("geo \$pa_overlay_dollar {\n    default \"\$\";\n}\n", $vars['overlay_http']);
    }

    public function test_overlays_sharing_a_position_are_joined_in_order(): void
    {
        $vars = OverlayDirectives::templateVars([
            new Overlay('<p>global</p>'),
            new Overlay('<style>p{}</style>', OverlayPosition::HeadEnd),
            new Overlay('<p>project</p>'),
        ]);

        $this->assertStringContainsString("sub_filter '</body>' '<p>global</p><p>project</p></body>';", $vars['overlay_location']);
        $this->assertStringContainsString("sub_filter '</head>' '<style>p{}</style></head>';", $vars['overlay_location']);
        $this->assertSame(2, substr_count($vars['overlay_location'], 'sub_filter \''));
    }

    /** A script needing every nginx escape, near the 16 KB limit, reads back byte for byte from its parts. */
    public function test_an_overlay_longer_than_nginx_reads_in_one_parameter_is_cut_into_variables(): void
    {
        $html = '<script>'.str_repeat("var s = '\\$'; // ż\n", 700).'</script>';
        $vars = OverlayDirectives::templateVars([new Overlay($html)]);
        $location = $vars['overlay_location'];

        preg_match_all('/^set \$(pa_overlay_body_end_\d+) \'((?:[^\'\\\\]|\\\\.)*)\';$/ms', $location, $sets);
        $this->assertGreaterThan(4, count($sets[1]));
        foreach ($sets[2] as $part) {
            $this->assertLessThanOrEqual(OverlayDirectives::MAX_PARAMETER_BYTES - 2, strlen($part));
        }
        $this->assertStringContainsString(
            "sub_filter '</body>' '".implode('', array_map(static fn (string $v): string => '${'.$v.'}', $sets[1]))."';",
            $location,
        );

        $read = '';
        foreach ($sets[2] as $part) {
            $read .= (string) preg_replace('/\\\\(.)/s', '$1', str_replace('${pa_overlay_dollar}', '$', $part));
        }
        $this->assertSame($html.'</body>', $read);
    }

    public function test_an_empty_or_oversized_overlay_is_refused(): void
    {
        foreach (["  \n", str_repeat('a', Overlay::MAX_HTML_BYTES + 1), "a\0b"] as $html) {
            try {
                new Overlay($html);
                $this->fail('Accepted '.json_encode(substr($html, 0, 10)));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_unusable_stored_overlay_reads_as_none(): void
    {
        $this->assertNull(Overlay::fromStored(null));
        $this->assertNull(Overlay::fromStored(['html' => '']));
        $this->assertNull(Overlay::fromStored(['position' => 'body_end']));
        $this->assertEquals(new Overlay('<b>x</b>'), Overlay::fromStored(['html' => '<b>x</b>', 'position' => 'nowhere']));
    }
}
