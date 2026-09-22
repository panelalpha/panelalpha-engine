<?php

namespace App\Lib\Deploy\Checkout;

/**
 * The block of Engine Artifacts the engine owns in a checkout's local exclude
 * file (`.git/info/exclude`), never in the client's `.gitignore` — ADR-0001.
 *
 * Excluded files stay out of `git status`, are never committed, and survive a
 * forced pull's `clean -fd`. Everything outside the markers belongs to the
 * client and is kept byte for byte.
 */
final class CheckoutExclude
{
    public const BEGIN = '# >>> panelalpha-engine';

    public const END = '# <<< panelalpha-engine';

    private const NOTE = '# Engine Artifacts, rewritten by PanelAlpha Engine on every deploy. Edits here are lost.';

    /**
     * @param list<string> $patterns gitignore patterns, one per line
     */
    public static function render(array $patterns): string
    {
        $lines = [self::BEGIN, self::NOTE, ...array_values(array_unique($patterns)), self::END];

        return implode("\n", $lines) . "\n";
    }

    /**
     * $existing with its engine block replaced by $block, or with $block
     * appended when it has none.
     */
    public static function merge(string $existing, string $block): string
    {
        $pattern = '/^' . preg_quote(self::BEGIN, '/') . '\n.*?^' . preg_quote(self::END, '/') . '(?:\n|$)/ms';
        if (preg_match($pattern, $existing) === 1) {
            return preg_replace_callback($pattern, static fn (): string => $block, $existing, 1);
        }

        if ($existing === '') {
            return $block;
        }

        return $existing . (str_ends_with($existing, "\n") ? "\n" : "\n\n") . $block;
    }

    /**
     * A checkout-relative path as a pattern matching that one path.
     *
     * The leading slash anchors it to the checkout root, so a line can never
     * start with `#` or `!`; glob characters and backslashes are escaped so a
     * directory named `[api]` is not read as a character class.
     */
    public static function anchoredPath(string $relative): string
    {
        return '/' . preg_replace('/([\\\\*?\[\]])/', '\\\\$1', ltrim($relative, '/'));
    }
}
