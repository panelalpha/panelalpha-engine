<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The coercions every reader repeats over hand-written package files (a `license` string or list,
 * an `author` object); a trait, so the `*Metadata.php` scan does not claim it.
 */
trait ReadsPackageFiles
{
    /** A trimmed non-empty string, or null for everything else. */
    protected static function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A list of strings from a scalar, an object, a list, or a list of objects: the
     * shapes `authors`, `keywords` and `license` turn up in. An object is one entry.
     *
     * @return list<string>
     */
    protected static function stringList(mixed $value, string $objectKey = 'name'): array
    {
        $single = self::text($value);
        if ($single !== null) {
            return [$single];
        }
        if (! is_array($value)) {
            return [];
        }
        if (! array_is_list($value)) {
            $value = [$value];
        }

        $out = [];
        foreach ($value as $entry) {
            $text = self::text($entry);
            if ($text === null && is_array($entry)) {
                $text = self::text($entry[$objectKey] ?? null);
            }
            if ($text !== null) {
                $out[$text] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * The keys of a map, as strings, in declaration order.
     *
     * @return list<string>
     */
    protected static function keys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (array_keys($value) as $key) {
            $key = self::text($key);
            if ($key !== null) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** The directory's own name, for a project whose files never gave one. */
    protected static function directoryName(ProjectContext $context): ?string
    {
        return self::text(basename($context->projectDir));
    }

    /**
     * The frameworks in $table this project depends on, in the table's order, which is
     * precedence: every Next.js project also depends on React.
     *
     * @param  array<string, string>  $table  package id => display name, most specific first
     * @param  array<string, string>  $constraints  package id => declared range
     * @param  array<string, string>  $versions  package id => resolved version, where one is known
     * @return list<Framework>
     */
    protected static function matchFrameworks(
        array $table,
        array $constraints,
        array $versions,
        string $constraintSource,
        ?string $versionSource = null
    ): array {
        $found = [];
        foreach ($table as $id => $name) {
            if (! isset($constraints[$id])) {
                continue;
            }
            $version = self::text($versions[$id] ?? null);
            $found[] = new Framework(
                $id,
                $name,
                $constraints[$id],
                $version,
                $version !== null && $versionSource !== null ? $versionSource : $constraintSource
            );
        }

        return $found;
    }

    /**
     * The body of a TOML table, without the tables that follow it: enough TOML for `[package]`
     * and `[project]`, answering null on anything else rather than adding a parser dependency.
     */
    protected static function tomlSection(string $toml, string $name): ?string
    {
        $pattern = '/^[ \t]*\['.preg_quote($name, '/').'\][ \t]*\r?$(.*?)(?=^[ \t]*\[|\z)/ms';

        return preg_match($pattern, $toml, $matches) === 1 ? $matches[1] : null;
    }

    /** A `key = "value"` out of a TOML table body. */
    protected static function tomlString(string $section, string $key): ?string
    {
        $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=[ \t]*(["\'])(.*?)\1/m';

        return preg_match($pattern, $section, $matches) === 1 ? self::text($matches[2]) : null;
    }

    /**
     * The strings in a `key = ["a", "b"]` out of a TOML table body.
     *
     * @return list<string>
     */
    protected static function tomlList(string $section, string $key): array
    {
        $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=[ \t]*\[(.*?)\]/ms';
        if (preg_match($pattern, $section, $matches) !== 1) {
            return [];
        }
        if (preg_match_all('/(["\'])(.*?)\1/s', $matches[1], $entries) !== false) {
            return array_values(array_filter(
                array_map(static fn (string $e): ?string => self::text($e), $entries[2] ?? []),
                static fn (?string $e): bool => $e !== null
            ));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    protected static function map(mixed $json): array
    {
        return is_array($json) ? $json : [];
    }

    /**
     * Declared dependency ranges as `name => constraint`, from any number of
     * `name: constraint` maps. The first section to name a dependency wins.
     *
     * @param  array<string, mixed>  ...$sections
     * @return array<string, string>
     */
    protected static function constraints(array ...$sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach ($section as $name => $constraint) {
                $name = self::text($name);
                if ($name === null || isset($out[$name])) {
                    continue;
                }
                $out[$name] = self::text($constraint) ?? '*';
            }
        }

        return $out;
    }
}
