<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\System;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a compose file the way Docker reads it. What Symfony rejects and Docker accepts is rescued only as a
 * fallback, so a file Symfony already parses is never rewritten: an anchor alone on its line moves onto its
 * key, a `---` behind leading comments is dropped, a blank line holding a tab is emptied, and Compose's merge
 * tags (`!reset`, `!override`) are read.
 */
final class ComposeYaml
{
    private static ?\Closure $privilegedRead = null;

    /** An anchor alone on its line, e.g. `&base-lychee-setup`, a trailing comment included. */
    private const STANDALONE_ANCHOR = '/^&[A-Za-z0-9_][A-Za-z0-9_.\-]*(?:[ \t]+#.*)?$/';

    /** Compose's merge tags: `!reset` drops what an extended service set, `!override` replaces it whole. */
    public const RESET_TAG = 'reset';

    public const OVERRIDE_TAG = 'override';

    /** The document start marker, a trailing comment allowed. */
    private const DOCUMENT_START = '/^---(?:[ \t]+#.*)?$/';

    /**
     * The parsed document, or null when it cannot be read at all, even rescued; merge tags give their values.
     *
     * @return array<mixed>|null
     */
    public static function parse(string $raw): ?array
    {
        $parsed = self::parseKeepingMergeTags($raw);

        return $parsed === null ? null : self::withoutMergeTags($parsed);
    }

    /**
     * The same, with every tagged value left a {@see TaggedValue}, for {@see ComposeExtends} to merge by.
     *
     * @return array<mixed>|null
     */
    public static function parseKeepingMergeTags(string $raw): ?array
    {
        try {
            $parsed = Yaml::parse($raw);

            return is_array($parsed) ? $parsed : null;
        } catch (ParseException) {
        }

        try {
            $parsed = Yaml::parse(self::rescued($raw), Yaml::PARSE_CUSTOM_TAGS);

            return is_array($parsed) ? $parsed : null;
        } catch (ParseException) {
            return null;
        }
    }

    /**
     * Every tagged value replaced by the value it tags.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    public static function withoutMergeTags(array $node): array
    {
        foreach ($node as $key => $value) {
            $node[$key] = self::untagged($value);
        }

        return $node;
    }

    private static function untagged(mixed $value): mixed
    {
        while ($value instanceof TaggedValue) {
            $value = $value->getValue();
        }

        return is_array($value) ? self::withoutMergeTags($value) : $value;
    }

    /**
     * The same, for a caller holding a path rather than the contents.
     *
     * @return array<mixed>|null
     */
    public static function parseFile(string $path): ?array
    {
        $raw = self::read($path);

        return is_string($raw) ? self::parse($raw) : null;
    }

    /**
     * Read as root when core cannot open it ({@see EngineArtifacts::RUN_COMPOSE_MODE}), and only in an account home.
     */
    public static function read(string $path): ?string
    {
        $raw = AccountHomeRead::contents($path);
        if (is_string($raw) || ! is_file($path) || ! AccountHomeRead::isInAccountHome($path)) {
            return is_string($raw) ? $raw : null;
        }

        return (self::$privilegedRead ?? static fn (string $p): ?string => (new System)->filesystem()->cat($p))($path);
    }

    /** Test seam for the root read, null restores it. */
    public static function readPrivilegedWith(?\Closure $read): void
    {
        self::$privilegedRead = $read;
    }

    /**
     * Write a parsed compose document back out, keeping `[]` and `{}` apart: an empty array is a
     * sequence where $source had one, and a map everywhere else.
     *
     * @param  array<mixed>  $compose
     * @param  string  ...$included  files merged into $source ({@see ComposeInclude})
     */
    public static function dump(array $compose, string $source, int $inline, int $indent, string ...$included): string
    {
        $sequences = self::emptySequences($source);
        foreach ($included as $raw) {
            $sequences += self::emptySequences($raw);
        }

        return Yaml::dump(
            self::emptiesMarked($compose, '', $sequences),
            $inline,
            $indent,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP
        );
    }

    /**
     * Paths of every empty sequence in $raw, read with maps as objects so the two kinds of empty differ.
     *
     * @return array<string, true>
     */
    private static function emptySequences(string $raw): array
    {
        foreach ([$raw, self::rescued($raw)] as $candidate) {
            try {
                $found = [];
                self::collectEmptySequences(Yaml::parse($candidate, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_CUSTOM_TAGS), '', $found);

                return $found;
            } catch (ParseException) {
                continue;
            }
        }

        return [];
    }

    /**
     * @param  array<string, true>  $found
     */
    private static function collectEmptySequences(mixed $node, string $path, array &$found): void
    {
        while ($node instanceof TaggedValue) {
            $node = $node->getValue();
        }
        if ($node === []) {
            $found[$path] = true;

            return;
        }
        if (! is_array($node) && ! $node instanceof \stdClass) {
            return;
        }
        foreach ((array) $node as $key => $value) {
            self::collectEmptySequences($value, $path."\0".$key, $found);
        }
    }

    /**
     * An empty map becomes an empty object, dumped as `{}`; an empty sequence stays `[]`.
     *
     * @param  array<mixed>  $node
     * @param  array<string, true>  $sequences
     * @return array<mixed>|\stdClass
     */
    private static function emptiesMarked(array $node, string $path, array $sequences): array|\stdClass
    {
        if ($node === []) {
            return isset($sequences[$path]) ? [] : new \stdClass;
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::emptiesMarked($value, $path."\0".$key, $sequences);
            }
        }

        return $node;
    }

    /** Every text rescue, each a no-op on a file without its construct. */
    private static function rescued(string $raw): string
    {
        return self::blankLinesEmptied(self::leadingDocumentStartDropped(self::anchorsOntoTheirKeys($raw)));
    }

    /**
     * A `---` with only comments and blanks above it opens the one document; Symfony counts the comments as a
     * document of their own. A second `---` is a real second document, and the file is left as written.
     */
    private static function leadingDocumentStartDropped(string $raw): string
    {
        $lines = preg_split('/\R/', $raw);
        if ($lines === false) {
            return $raw;
        }
        $starts = array_keys(array_filter($lines, static fn (string $l): bool => preg_match(self::DOCUMENT_START, rtrim($l)) === 1));
        if (count($starts) !== 1 || ! self::onlyCommentsAbove($lines, $starts[0])) {
            return $raw;
        }
        $lines[$starts[0]] = '';

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private static function onlyCommentsAbove(array $lines, int $i): bool
    {
        foreach (array_slice($lines, 0, $i) as $above) {
            $trimmed = trim($above);
            if ($trimmed !== '' && ! str_starts_with($trimmed, '#')) {
                return false;
            }
        }

        return true;
    }

    /** A line of only spaces and tabs is blank to Docker; Symfony reads the tab as indentation. */
    private static function blankLinesEmptied(string $raw): string
    {
        return (string) preg_replace('/^[ \t]+$/m', '', $raw);
    }

    /**
     * Move an anchor alone on its line onto its key (`key:\n  &a\n  x: 1` to `key: &a\n  x: 1`), only when the
     * nearest line above, skipping blanks and comments, ends in a colon; anything else is left as written.
     */
    private static function anchorsOntoTheirKeys(string $raw): string
    {
        $lines = preg_split('/\R/', $raw);
        if ($lines === false) {
            return $raw;
        }

        $moved = false;
        foreach ($lines as $i => $line) {
            if (preg_match(self::STANDALONE_ANCHOR, trim($line)) !== 1) {
                continue;
            }

            $j = self::keyLineAbove($lines, $i);
            if ($j !== null) {
                $lines[$j] = rtrim($lines[$j]).' '.trim($line);
                $lines[$i] = null;
                $moved = true;
            }
        }

        if (! $moved) {
            return $raw;
        }

        return implode("\n", array_filter($lines, static fn ($l): bool => $l !== null));
    }

    /**
     * @param  array<int, ?string>  $lines
     */
    private static function keyLineAbove(array $lines, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $above = $lines[$j];
            if ($above === null) {
                continue;
            }
            $trimmed = trim($above);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            return str_ends_with($trimmed, ':') ? $j : null;
        }

        return null;
    }
}
