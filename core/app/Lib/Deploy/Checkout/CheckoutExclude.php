<?php

namespace App\Lib\Deploy\Checkout;

/**
 * The engine's block of Engine Artifacts in `.git/info/exclude`, never `.gitignore` (ADR-0001), so they stay
 * uncommitted and survive a forced pull's `clean -fd`; everything outside the markers is the client's, kept as is.
 */
final class CheckoutExclude
{
    public const BEGIN = '# >>> panelalpha-engine';

    public const END = '# <<< panelalpha-engine';

    private const NOTE = '# Engine Artifacts, rewritten by PanelAlpha Engine on every deploy. Edits here are lost.';

    /**
     * @param  list<string>  $patterns  gitignore patterns, one per line
     */
    public static function render(array $patterns): string
    {
        $lines = [self::BEGIN, self::NOTE, ...array_values(array_unique($patterns)), self::END];

        return implode("\n", $lines)."\n";
    }

    /**
     * $existing with its engine block replaced by $block, or with $block
     * appended when it has none.
     */
    public static function merge(string $existing, string $block): string
    {
        $pattern = '/^'.preg_quote(self::BEGIN, '/').'\n.*?^'.preg_quote(self::END, '/').'(?:\n|$)/ms';
        if (preg_match($pattern, $existing) === 1) {
            return preg_replace_callback($pattern, static fn (): string => $block, $existing, 1);
        }

        if ($existing === '') {
            return $block;
        }

        return $existing.(str_ends_with($existing, "\n") ? "\n" : "\n\n").$block;
    }

    /**
     * A checkout-relative path as a pattern matching only that path: the leading slash keeps a line from
     * starting with `#` or `!`, and glob characters are escaped so `[api]` is no character class.
     */
    public static function anchoredPath(string $relative): string
    {
        return '/'.preg_replace('/([\\\\*?\[\]])/', '\\\\$1', ltrim($relative, '/'));
    }
}
