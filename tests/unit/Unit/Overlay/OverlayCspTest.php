<?php

namespace Tests\Unit\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayCsp;
use App\Lib\Overlay\OverlayDirectives;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OverlayCspTest extends TestCase
{
    private const string SCRIPT = "<script>(function(){document.body.appendChild(document.createElement('div'));})();</script>";

    private const string STYLE = '<style>#pa-bar{color:red}</style>';

    public function test_an_inline_script_is_hashed_as_the_browser_does(): void
    {
        $this->assertSame(["'sha256-".base64_encode(hash('sha256', 'go()', true))."'"], OverlayCsp::hashes('<p>x</p><script type="module">go()</script>', 'script'));
    }

    public function test_a_script_with_src_has_nothing_inline_to_hash(): void
    {
        $this->assertSame([], OverlayCsp::hashes('<script src="/a.js"></script><script defer src=/b.js></script>', 'script'));
    }

    public function test_line_endings_are_hashed_as_the_html_parser_leaves_them(): void
    {
        $this->assertSame(OverlayCsp::hashes("<script>a\nb\nc</script>", 'script'), OverlayCsp::hashes("<script>a\r\nb\rc</script>", 'script'));
    }

    public function test_each_element_is_hashed_once(): void
    {
        $this->assertCount(2, OverlayCsp::hashes('<style>a{}</style><style>b{}</style><STYLE>a{}</STYLE>', 'style'));
    }

    public function test_an_overlay_without_inline_script_or_style_leaves_the_policy_alone(): void
    {
        $overlays = [new Overlay('<div id="pa-banner"><img src="/logo.svg"></div><script src="/x.js"></script>')];

        $this->assertSame('', OverlayCsp::httpDirectives($overlays));
        $this->assertSame('', OverlayCsp::locationDirectives($overlays));
    }

    public function test_the_header_sent_is_the_last_map_s_variable_named_after_the_hashes(): void
    {
        $overlays = [new Overlay(self::SCRIPT.self::STYLE)];
        $location = OverlayCsp::locationDirectives($overlays);

        $this->assertMatchesRegularExpression('/^proxy_hide_header Content-Security-Policy;\nadd_header Content-Security-Policy \$(pa_overlay_csp_[0-9a-f]{16}) always;$/', $location);
        preg_match('/\$(pa_overlay_csp_[0-9a-f]{16})/', $location, $variable);
        $this->assertStringContainsString('_script $'.$variable[1].' {', OverlayCsp::httpDirectives($overlays));
    }

    public function test_the_same_overlay_defines_the_same_maps_and_another_overlay_other_ones(): void
    {
        // Map variables are global to nginx's http block: every vhost writes its own.
        $one = OverlayCsp::httpDirectives([new Overlay(self::SCRIPT)]);

        $this->assertSame($one, OverlayCsp::httpDirectives([new Overlay(self::SCRIPT)]));
        $this->assertNotSame(self::variables($one), self::variables(OverlayCsp::httpDirectives([new Overlay('<script>other()</script>')])));
    }

    /**
     * What nginx 1.31 sent for each upstream policy with these very maps
     * (checked by hand against a real nginx and Chrome; this replays them).
     *
     * @return array<string, array{string, string}>
     */
    public static function policies(): array
    {
        $s = self::hash(self::SCRIPT, 'script');
        $c = self::hash(self::STYLE, 'style');

        return [
            'no policy: none added' => ['', ''],
            'only default-src (Kanboard): a script-src copying it' => [
                "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:;",
                "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:; script-src 'self' $s",
            ],
            'only default-src: script-src and style-src copying it' => [
                "default-src 'self' https://x.example",
                "default-src 'self' https://x.example; script-src 'self' https://x.example $s; style-src 'self' https://x.example $c",
            ],
            'script-src and style-src: the hashes appended' => [
                "default-src 'none'; script-src 'self' https://cdn.example; style-src 'self'",
                "default-src 'none'; script-src 'self' https://cdn.example $s; style-src 'self' $c",
            ],
            'script-src-elem governs inline scripts, not script-src' => [
                "script-src 'self'; script-src-elem 'self'",
                "script-src 'self'; script-src-elem 'self' $s",
            ],
            'directive names in any case' => [
                "Script-Src 'self'",
                "Script-Src 'self' $s",
            ],
            'unsafe-inline: inline already runs, a hash would switch it off' => [
                "script-src 'self' 'unsafe-inline'",
                "script-src 'self' 'unsafe-inline'",
            ],
            'unsafe-inline beside a nonce is off already: appended' => [
                "script-src 'self' 'unsafe-inline' 'nonce-abc'",
                "script-src 'self' 'unsafe-inline' 'nonce-abc' $s",
            ],
            'unsafe-inline beside strict-dynamic: appended' => [
                "script-src 'strict-dynamic' 'unsafe-inline' 'sha256-abc='",
                "script-src 'strict-dynamic' 'unsafe-inline' 'sha256-abc=' $s",
            ],
            'unsafe-inline in default-src, no script-src: left' => [
                "default-src 'self' 'unsafe-inline'",
                "default-src 'self' 'unsafe-inline'",
            ],
            'script-src none: the site wants no script' => [
                "script-src 'none'; style-src 'none'",
                "script-src 'none'; style-src 'none'",
            ],
            'default-src none, nothing else: left' => [
                "default-src 'none'",
                "default-src 'none'",
            ],
            'several policies in one header: left' => [
                "script-src 'self', img-src *",
                "script-src 'self', img-src *",
            ],
            'nothing governs scripts or styles: left' => [
                'img-src *; frame-ancestors none',
                'img-src *; frame-ancestors none',
            ],
            'script-src-attr does not govern elements' => [
                "script-src-attr 'none'; default-src 'self'",
                "script-src-attr 'none'; default-src 'self'; script-src 'self' $s; style-src 'self' $c",
            ],
        ];
    }

    #[DataProvider('policies')]
    public function test_the_upstream_policy_becomes(string $upstream, string $expected): void
    {
        $overlays = [new Overlay(self::SCRIPT), new Overlay(self::STYLE)];
        preg_match('/add_header Content-Security-Policy \$(\S+) always;/', OverlayCsp::locationDirectives($overlays), $sent);

        $this->assertSame($expected, self::runMaps(OverlayCsp::httpDirectives($overlays), $upstream, $sent[1]));
    }

    public function test_the_overlay_directives_carry_the_maps_and_the_header(): void
    {
        $vars = OverlayDirectives::templateVars([new Overlay('<script>const $x = 1;</script>')]);

        $this->assertStringStartsWith("geo \$pa_overlay_dollar {\n    default \"\$\";\n}\nmap \$upstream_http_content_security_policy ", $vars['overlay_http']);
        $this->assertStringEndsWith("add_header Content-Security-Policy \$".array_slice(self::variables($vars['overlay_http']), -1)[0].' always;', $vars['overlay_location']);
        // The hash is of the script as the page gets it, not of its nginx escaping.
        $this->assertStringContainsString(self::hash('<script>const $x = 1;</script>', 'script'), $vars['overlay_http']);
    }

    private static function hash(string $html, string $element): string
    {
        return OverlayCsp::hashes($html, $element)[0];
    }

    /**
     * @return list<string> the variables the maps define, in order
     */
    private static function variables(string $http): array
    {
        preg_match_all('/^map \$\S+ \$(\S+) \{$/m', $http, $matches);

        return $matches[1];
    }

    /**
     * nginx's map, for these maps: regexes in order, the first match wins
     * (`~*`: caseless), `$1`.. its captures, `$name` a variable; none: default.
     */
    private static function runMaps(string $http, string $upstream, string $wanted): string
    {
        $variables = ['upstream_http_content_security_policy' => $upstream];
        preg_match_all('/^map \$(\S+) \$(\S+) \{\n(.*?)\n\}$/ms', $http, $maps, PREG_SET_ORDER);
        self::assertNotEmpty($maps);
        foreach ($maps as [, $source, $target, $body]) {
            $input = $variables[$source];
            preg_match('/^    default \$(\S+);$/m', $body, $default);
            $value = $variables[$default[1]];
            preg_match_all('/^    "~\*(.*)" "(.*)";$/m', $body, $rules, PREG_SET_ORDER);
            foreach ($rules as [, $regex, $result]) {
                if (preg_match('#'.$regex.'#i', $input, $captures)) {
                    $value = preg_replace_callback('/\$(\d+|[a-z_][a-z0-9_]*)/', static fn (array $m): string => ctype_digit($m[1]) ? ($captures[(int) $m[1]] ?? '') : $variables[$m[1]], $result);
                    break;
                }
            }
            $variables[$target] = $value;
        }

        return $variables[$wanted];
    }
}
