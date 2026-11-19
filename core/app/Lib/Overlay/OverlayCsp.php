<?php

namespace App\Lib\Overlay;

/**
 * Lets an overlay's own inline <script> and <style> elements past the site's
 * Content-Security-Policy: the proxy adds their sha256 hashes to the upstream
 * header, and nothing else. A policy with no `default-src` but `script-src`
 * `'self'` (Kanboard's) otherwise refuses the overlay's script outright.
 *
 * The hash goes into the directive that governs the element (`script-src-elem`,
 * else `script-src`, else a new `script-src` copying `default-src`; the same
 * for styles). The header is left as it is where adding it would change what
 * the site allows: no policy at all, `'unsafe-inline'` without a nonce or hash
 * (a hash would switch it off for the site's own inline code), `'none'`,
 * several policies in one header. Not covered: a policy in a <meta> tag,
 * inline event handlers (they would need 'unsafe-hashes'), elements the
 * overlay's script creates later, Content-Security-Policy-Report-Only.
 *
 * Plain nginx `map`s, read once per answer: the variables are named after the
 * hashes, so vhosts with the same overlay define the same maps and different
 * overlays never share one (map variables are global to the http block).
 */
final class OverlayCsp
{
    public const string VARIABLE = 'pa_overlay_csp_';

    private const string UPSTREAM = '$upstream_http_content_security_policy';

    /** Element => [the directives that govern it, most specific first]. */
    private const array DIRECTIVES = [
        'script' => ['script-src-elem', 'script-src'],
        'style' => ['style-src-elem', 'style-src'],
    ];

    /**
     * The CSP hash sources of the inline <$element> elements in $html (a
     * <script src> has nothing inline to hash), as the browser computes them:
     * over the element's text, line endings as the HTML parser leaves them.
     *
     * @return list<string>
     */
    public static function hashes(string $html, string $element): array
    {
        $tag = preg_quote($element, '/');
        if (! preg_match_all('/<'.$tag.'\b(?![^>]*\ssrc\s*=)[^>]*>(.*?)<\/'.$tag.'\s*>/is', $html, $matches)) {
            return [];
        }

        $hashes = [];
        foreach ($matches[1] as $text) {
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $hashes[] = "'sha256-".base64_encode(hash('sha256', $text, true))."'";
        }

        return array_values(array_unique($hashes));
    }

    /**
     * The http-level maps rewriting the upstream policy, or '' when the
     * overlays have no inline script or style.
     *
     * @param  list<Overlay>  $overlays
     */
    public static function httpDirectives(array $overlays): string
    {
        $hashes = self::allHashes($overlays);
        if ($hashes === []) {
            return '';
        }

        $name = self::variable($hashes);
        $maps = [];
        $source = self::UPSTREAM;
        $kinds = array_keys(array_filter($hashes));
        foreach ($kinds as $i => $element) {
            $target = $i === count($kinds) - 1 ? '$'.$name : '$'.$name.'_'.$element;
            $maps[] = self::map($source, $target, $element, $hashes[$element]);
            $source = $target;
        }

        return implode("\n", $maps);
    }

    /**
     * The location directives sending the rewritten policy instead of the
     * upstream one (an empty value sends no header: no policy stays none), or ''.
     *
     * @param  list<Overlay>  $overlays
     */
    public static function locationDirectives(array $overlays): string
    {
        $hashes = self::allHashes($overlays);
        if ($hashes === []) {
            return '';
        }

        return "proxy_hide_header Content-Security-Policy;\n"
            .'add_header Content-Security-Policy $'.self::variable($hashes).' always;';
    }

    /**
     * @param  list<Overlay>  $overlays
     * @return array<string, list<string>> element => hashes, only elements that have some
     */
    private static function allHashes(array $overlays): array
    {
        $html = implode('', array_map(static fn (Overlay $overlay): string => $overlay->html, $overlays));
        $hashes = [];
        foreach (array_keys(self::DIRECTIVES) as $element) {
            $found = self::hashes($html, $element);
            if ($found !== []) {
                $hashes[$element] = $found;
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, list<string>>  $hashes
     */
    private static function variable(array $hashes): string
    {
        return self::VARIABLE.substr(hash('sha256', (string) json_encode($hashes)), 0, 16);
    }

    /**
     * One map: the first rule that matches wins, in order; `default` keeps the
     * policy as it came (no governing directive: the element is not restricted).
     *
     * @param  list<string>  $hashes
     */
    private static function map(string $source, string $target, string $element, array $hashes): string
    {
        $sources = implode(' ', $hashes);
        $rules = [
            // Several policies in one header (comma-joined): each is enforced, too easy to get wrong.
            [',', $source],
        ];
        foreach (self::DIRECTIVES[$element] as $directive) {
            $rules = [...$rules, ...self::keepRules($directive, $source), [
                // Present: the hashes go at the end of its sources.
                '^((?:[^;]*;)*?\s*'.$directive.'(?=[\s;]|$)[^;]*)(.*)$', '$1 '.$sources.'$2',
            ]];
        }
        $rules = [...$rules, ...self::keepRules('default-src', $source), [
            // Only default-src: a new directive with its sources (a trailing `;` dropped), so nothing else changes.
            '^((?:[^;]*;)*?\s*default-src(?=[\s;]|$)([^;]*).*?)[\s;]*$', '$1; '.self::DIRECTIVES[$element][1].'$2 '.$sources,
        ]];

        $lines = ['map '.$source.' '.$target.' {', '    default '.$source.';'];
        foreach ($rules as [$regex, $value]) {
            $lines[] = '    "~*'.$regex.'" "'.$value.'";';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * When $directive governs and allows no script/style at all, or every
     * inline one, the policy stays as it is.
     *
     * @return list<array{string, string}>
     */
    private static function keepRules(string $directive, string $source): array
    {
        $at = '(?:^|;)\s*'.$directive.'(?=[\s;]|$)';

        return [
            [$at."\s*'none'\s*(?:;|$)", $source],
            [$at."(?![^;]*'(?:nonce-|sha256-|sha384-|sha512-|strict-dynamic'))[^;]*'unsafe-inline'", $source],
        ];
    }
}
