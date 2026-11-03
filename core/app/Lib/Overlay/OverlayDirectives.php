<?php

namespace App\Lib\Overlay;

/**
 * The nginx sub_filter directives that write overlays into HTML pages, with the upstream answer requested uncompressed.
 * `$` goes through a `geo` variable and a replacement over {@see MAX_PARAMETER_BYTES} through `set` chunks, as nginx needs.
 */
final class OverlayDirectives
{
    public const string DOLLAR_VARIABLE = 'pa_overlay_dollar';

    /** The longest quoted parameter written, well inside nginx's 4096-byte buffer. */
    public const int MAX_PARAMETER_BYTES = 3000;

    /** The `set` variables a long overlay is cut into: `pa_overlay_<position>_<n>`. */
    public const string PART_VARIABLE = 'pa_overlay_';

    /**
     * @param  list<Overlay>  $overlays
     * @return array{overlay_enabled: bool, overlay_http?: string, overlay_location?: string}
     */
    public static function templateVars(array $overlays): array
    {
        if ($overlays === []) {
            return ['overlay_enabled' => false];
        }

        return [
            'overlay_enabled' => true,
            'overlay_http' => self::httpDirectives($overlays),
            'overlay_location' => self::locationDirectives($overlays),
        ];
    }

    /**
     * @param  list<Overlay>  $overlays
     */
    private static function httpDirectives(array $overlays): string
    {
        foreach ($overlays as $overlay) {
            if (str_contains($overlay->html, '$')) {
                return 'geo $'.self::DOLLAR_VARIABLE." {\n    default \"\$\";\n}";
            }
        }

        return '';
    }

    /**
     * @param  list<Overlay>  $overlays
     */
    private static function locationDirectives(array $overlays): string
    {
        $lines = [
            'proxy_set_header Accept-Encoding "";',
            'sub_filter_once on;',
        ];
        foreach (self::htmlByPosition($overlays) as $position => $html) {
            $closingTag = OverlayPosition::from($position)->closingTag();
            $replacement = self::quoted($html.$closingTag);
            if (strlen($replacement) <= self::MAX_PARAMETER_BYTES) {
                $lines[] = 'sub_filter '.self::quoted($closingTag).' '.$replacement.';';

                continue;
            }

            $variables = '';
            foreach (self::parts($html.$closingTag) as $n => $part) {
                $variable = self::PART_VARIABLE.$position.'_'.$n;
                $lines[] = 'set $'.$variable." '".$part."';";
                $variables .= '${'.$variable.'}';
            }
            $lines[] = 'sub_filter '.self::quoted($closingTag)." '".$variables."';";
        }

        return implode("\n", $lines);
    }

    /**
     * $text escaped as {@see quoted()} does, cut into pieces of at most
     * MAX_PARAMETER_BYTES (quotes excluded), never inside an escape.
     *
     * @return list<string>
     */
    private static function parts(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            $characters = str_split($text);
        }

        $parts = [];
        $part = '';
        foreach ($characters as $character) {
            $escaped = self::escaped($character);
            if ($part !== '' && strlen($part) + strlen($escaped) > self::MAX_PARAMETER_BYTES - 2) {
                $parts[] = $part;
                $part = '';
            }
            $part .= $escaped;
        }
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Overlays sharing a position are joined in order: nginx takes one
     * replacement per searched string.
     *
     * @param  list<Overlay>  $overlays
     * @return array<string, string>
     */
    private static function htmlByPosition(array $overlays): array
    {
        $html = [];
        foreach ($overlays as $overlay) {
            $position = $overlay->position->value;
            $html[$position] = ($html[$position] ?? '').$overlay->html;
        }

        return $html;
    }

    /**
     * A single-quoted nginx string holding exactly $text: nginx reads `\\` and `\'`
     * back as the character, and `$` would start a variable.
     */
    public static function quoted(string $text): string
    {
        return "'".self::escaped($text)."'";
    }

    /** $text for inside a single-quoted nginx string. */
    private static function escaped(string $text): string
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $text);

        return str_replace('$', '${'.self::DOLLAR_VARIABLE.'}', $escaped);
    }
}
