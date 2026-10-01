<?php

namespace App\Lib\Deploy\Compose;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a compose file the way Docker reads it, not the way Symfony does.
 *
 * The two disagree on one construct, and a project is entitled to the Docker
 * answer: its file works, and the engine refusing it is the engine's problem.
 * Lychee declares an anchor on a line of its own —
 *
 *     x-base-lychee-setup:
 *       # a comment
 *       &base-lychee-setup
 *       image: ghcr.io/lycheeorg/lychee:latest
 *
 * — which is legal YAML, which `docker compose` accepts, and which Symfony's
 * parser rejects with "Mapping values are not allowed in multi-line blocks".
 * That exception reached the API verbatim and took the account with it: the
 * deploy failed at the `running` stage, the account was rolled back, and the
 * deploy log was deleted before anyone could read why.
 *
 * So the anchor is moved onto the key above it, which is the same node in
 * canonical form, and only ever as a **fallback**: a file Symfony already
 * parses is never rewritten, so this cannot change the meaning of a file that
 * was working.
 */
final class ComposeYaml
{
    /**
     * An anchor alone on its line, e.g. `&base-lychee-setup` -- a trailing
     * comment included, because Baserow writes one there and an anchor with
     * something said about it is still an anchor alone on its line.
     */
    private const STANDALONE_ANCHOR = '/^&[A-Za-z0-9_][A-Za-z0-9_.\-]*(?:[ \t]+#.*)?$/';

    /**
     * The parsed document, or null when it cannot be read at all.
     *
     * @return array<mixed>|null
     */
    public static function parse(string $raw): ?array
    {
        try {
            $parsed = Yaml::parse($raw);

            return is_array($parsed) ? $parsed : null;
        } catch (ParseException) {
            // Fall through to the one construct worth rescuing.
        }

        $rescued = self::anchorsOntoTheirKeys($raw);
        if ($rescued === $raw) {
            return null;
        }

        try {
            $parsed = Yaml::parse($rescued);

            return is_array($parsed) ? $parsed : null;
        } catch (ParseException) {
            return null;
        }
    }

    /**
     * The same, for a caller holding a path rather than the contents.
     *
     * @return array<mixed>|null
     */
    public static function parseFile(string $path): ?array
    {
        $raw = @file_get_contents($path);

        return is_string($raw) ? self::parse($raw) : null;
    }

    /**
     * Write a parsed compose document back out, keeping `[]` and `{}` apart.
     *
     * Both parse to a PHP `[]`, and Symfony dumps that as `{}` -- so the
     * documented `healthcheck: { test: [] }` came back as `test: {}`, which
     * Compose rejects. An empty array is written as a sequence where $source
     * had one, and as a map everywhere else, as before.
     *
     * @param array<mixed> $compose
     * @param string ...$included files merged into $source ({@see ComposeInclude})
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
     * Paths of every empty sequence in $raw, read with maps as objects so the
     * two kinds of empty stay distinguishable.
     *
     * @return array<string, true>
     */
    private static function emptySequences(string $raw): array
    {
        foreach ([$raw, self::anchorsOntoTheirKeys($raw)] as $candidate) {
            try {
                $found = [];
                self::collectEmptySequences(Yaml::parse($candidate, Yaml::PARSE_OBJECT_FOR_MAP), '', $found);

                return $found;
            } catch (ParseException) {
                continue;
            }
        }

        return [];
    }

    /**
     * @param array<string, true> $found
     */
    private static function collectEmptySequences(mixed $node, string $path, array &$found): void
    {
        if ($node === []) {
            $found[$path] = true;

            return;
        }
        if (!is_array($node) && !$node instanceof \stdClass) {
            return;
        }
        foreach ((array) $node as $key => $value) {
            self::collectEmptySequences($value, $path . "\0" . $key, $found);
        }
    }

    /**
     * An empty map becomes an empty object, which DUMP_OBJECT_AS_MAP writes as
     * `{}`; an empty sequence stays `[]`, which DUMP_EMPTY_ARRAY_AS_SEQUENCE
     * writes as `[]`.
     *
     * @param array<mixed> $node
     * @param array<string, true> $sequences
     * @return array<mixed>|\stdClass
     */
    private static function emptiesMarked(array $node, string $path, array $sequences): array|\stdClass
    {
        if ($node === []) {
            return isset($sequences[$path]) ? [] : new \stdClass();
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::emptiesMarked($value, $path . "\0" . $key, $sequences);
            }
        }

        return $node;
    }

    /**
     * Move an anchor that sits alone on its line up onto the key it belongs
     * to, turning `key:\n  &a\n  x: 1` into `key: &a\n  x: 1`.
     *
     * Only a line that is nothing but an anchor is touched, and only when the
     * nearest line above it — skipping blanks and comments — ends in a colon,
     * which is the one shape where the move is unambiguous. Anything else is
     * left exactly as written rather than guessed at.
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

            for ($j = $i - 1; $j >= 0; $j--) {
                $above = $lines[$j];
                $trimmed = trim($above);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (str_ends_with($trimmed, ':')) {
                    $lines[$j] = rtrim($above) . ' ' . trim($line);
                    $lines[$i] = null;
                    $moved = true;
                }
                break;
            }
        }

        if (!$moved) {
            return $raw;
        }

        return implode("\n", array_filter($lines, static fn ($l): bool => $l !== null));
    }
}
