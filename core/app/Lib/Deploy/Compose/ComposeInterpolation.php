<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\EnvFile;

/**
 * Every value a compose string can take once Compose interpolates it, taking each variable with every
 * value the project may give it, so the hardener can refuse a mount source if any result is forbidden.
 */
final class ComposeInterpolation
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /** Set in the account container's own environment, which outranks `.env`: the engine cannot know them. */
    private const PROCESS_VARIABLES = ['HOME', 'PATH', 'HOSTNAME'];

    /** Past this many combinations the value is treated as unknowable. */
    private const MAX_RESULTS = 64;

    /**
     * @param  array<string, list<?string>|string>  $env  the values each variable may
     *                                                    have, null for unset; a variable not listed is unset
     * @return list<string>|null every result, or null when it cannot be told:
     *                           bad syntax, a process variable, or a value that itself interpolates
     */
    public static function candidates(string $value, array $env): ?array
    {
        $parts = self::parse($value, $env);

        return $parts === null ? null : array_values(array_unique($parts));
    }

    /**
     * What a compose file's variables may be set to: `.env`, else `.env.example`, plus the account's env_vars.
     * Only a key already in `.env` cannot end up unset: the deploy and the prepare hooks only add to it.
     *
     * @param  array<string, string>  $accountVars
     * @return array<string, list<?string>>
     */
    public static function environment(?string $dotEnv, ?string $example, array $accountVars): array
    {
        $env = [];
        foreach (EnvFile::parse($dotEnv ?? $example ?? '') as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $env[(string) $row['key']] = $dotEnv === null
                    ? [(string) ($row['value'] ?? ''), null]
                    : [(string) ($row['value'] ?? '')];
            }
        }
        foreach ($accountVars as $key => $value) {
            $states = $env[$key] ?? [null];
            if (is_string($key) && (string) $value !== '' && ! in_array((string) $value, $states, true)) {
                $env[$key] = [...$states, (string) $value];
            }
        }

        return $env;
    }

    /**
     * Compose's rules: `$$` is a literal `$`; `$VAR`, `${VAR}` and `${VAR<op>word}`, with nesting in the word.
     *
     * @param  array<string, list<?string>|string>  $env
     * @return list<string>|null
     */
    private static function parse(string $value, array $env): ?array
    {
        $results = [''];
        $length = strlen($value);
        $literal = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char !== '$') {
                $literal .= $char;

                continue;
            }
            $next = $value[$i + 1] ?? '';
            if ($next === '$') {
                $literal .= '$';
                $i++;

                continue;
            }

            $reference = self::reference($value, $i, $next, $env);
            if ($reference === null || $reference[0] === null) {
                return null;
            }
            [$alternatives, $i] = $reference;

            $results = self::combined($results, $literal, $alternatives);
            $literal = '';
            if (count($results) > self::MAX_RESULTS) {
                return null;
            }
        }

        return array_map(static fn (string $prefix): string => $prefix.$literal, $results);
    }

    /**
     * The values the `$` at $i can take and the offset it ends at; null for bad syntax, a lone `$` included.
     *
     * @param  array<string, list<?string>|string>  $env
     * @return array{0: list<string>|null, 1: int}|null
     */
    private static function reference(string $value, int $i, string $next, array $env): ?array
    {
        if ($next === '{') {
            $end = self::closingBrace($value, $i + 1);
            if ($end === null
                || preg_match('/^('.self::NAME.')(?:(:?[-+?])(.*))?$/s', substr($value, $i + 2, $end - $i - 2), $m) !== 1
            ) {
                return null;
            }

            return [self::variable($m[1], $m[2] ?? '', $m[3] ?? '', $env), $end];
        }
        if (preg_match('/^'.self::NAME.'/', substr($value, $i + 1), $m) === 1) {
            return [self::variable($m[0], '', '', $env), $i + strlen($m[0])];
        }

        return null;
    }

    /**
     * @param  list<string>  $results
     * @param  list<string>  $alternatives
     * @return list<string>
     */
    private static function combined(array $results, string $literal, array $alternatives): array
    {
        $combined = [];
        foreach ($results as $prefix) {
            foreach ($alternatives as $alternative) {
                $combined[] = $prefix.$literal.$alternative;
            }
        }

        return array_values(array_unique($combined));
    }

    /**
     * The values `${name<operator>word}` can take, once per state the variable may be in; an `:?` error
     * has none. A value in .env that interpolates again is not followed.
     *
     * @param  array<string, list<?string>|string>  $env
     * @return list<string>|null
     */
    private static function variable(string $name, string $operator, string $word, array $env): ?array
    {
        if (in_array($name, self::PROCESS_VARIABLES, true)) {
            return null;
        }
        $words = null;
        $wordValues = static function () use (&$words, $word, $env): ?array {
            return $words ??= self::parse($word, $env);
        };

        $states = array_key_exists($name, $env) ? (array) $env[$name] : [null];
        $values = [];
        foreach ($states as $set) {
            if (is_string($set) && str_contains($set, '$')) {
                return null;
            }
            $use = self::operated($operator, $set, $wordValues);
            if ($use === null) {
                return null;
            }
            array_push($values, ...$use);
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<?string>|null
     */
    private static function operated(string $operator, mixed $set, \Closure $wordValues): ?array
    {
        $nonEmpty = is_string($set) && $set !== '';

        return match ($operator) {
            '' => [$set ?? ''],
            ':-' => $nonEmpty ? [$set] : $wordValues(),
            '-' => $set !== null ? [$set] : $wordValues(),
            ':+' => $nonEmpty ? $wordValues() : [''],
            '+' => $set !== null ? $wordValues() : [''],
            ':?' => $nonEmpty ? [$set] : [],
            '?' => $set !== null ? [$set] : [],
            default => null,
        };
    }

    private static function closingBrace(string $value, int $open): ?int
    {
        $depth = 0;
        $length = strlen($value);
        for ($i = $open; $i < $length; $i++) {
            if ($value[$i] === '{') {
                $depth++;
            } elseif ($value[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
