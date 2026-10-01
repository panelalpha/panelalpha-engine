<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\EnvFile;

/**
 * Every value a compose string can take once Compose interpolates it.
 *
 * The hardener checks mount sources, and a source written as `${X:-/var/run}`
 * or `${X}` is a path only after interpolation. The file is hardened before
 * the deploy writes `.env` (and a recipe's prepare hook can add to it later),
 * so the value is not one fixed string: each variable is taken with every
 * value the project may give it, and unset unless `.env` already sets it. A
 * caller refuses the source if any of the results is forbidden.
 *
 * Compose's own rules: `$$` is a literal `$`; `$VAR`, `${VAR}`, and
 * `${VAR:-d}`, `${VAR-d}`, `${VAR:+a}`, `${VAR+a}`, `${VAR:?e}`, `${VAR?e}`,
 * with nesting in the word.
 */
final class ComposeInterpolation
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * Set in the account container's own environment, which outranks `.env`:
     * the engine cannot know their value. HOME is /root there.
     */
    private const PROCESS_VARIABLES = ['HOME', 'PATH', 'HOSTNAME'];

    /** Past this many combinations the value is treated as unknowable. */
    private const MAX_RESULTS = 64;

    /**
     * @param array<string, list<?string>|string> $env the values each variable may
     *        have, null for unset; a variable not listed is unset
     * @return list<string>|null every result, or null when it cannot be told:
     *         bad syntax, a process variable, or a value that itself interpolates
     */
    public static function candidates(string $value, array $env): ?array
    {
        $parts = self::parse($value, $env);

        return $parts === null ? null : array_values(array_unique($parts));
    }

    /**
     * What a compose file's variables may be set to: the value `.env` gives
     * them, `.env.example` when there is no `.env` yet (the deploy copies it,
     * less the keys the compose file defaults), and the account's own
     * env_vars, which the deploy merges in. Only a key already in `.env`
     * cannot end up unset: the deploy and the prepare hooks only add to it.
     *
     * @param array<string, string> $accountVars
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
            if (is_string($key) && (string) $value !== '' && !in_array((string) $value, $states, true)) {
                $env[$key] = [...$states, (string) $value];
            }
        }

        return $env;
    }

    /**
     * @param array<string, list<?string>|string> $env
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

            if ($next === '{') {
                $end = self::closingBrace($value, $i + 1);
                if ($end === null
                    || preg_match('/^(' . self::NAME . ')(?:(:?[-+?])(.*))?$/s', substr($value, $i + 2, $end - $i - 2), $m) !== 1
                ) {
                    return null;
                }
                $alternatives = self::variable($m[1], $m[2] ?? '', $m[3] ?? '', $env);
                $i = $end;
            } elseif (preg_match('/^' . self::NAME . '/', substr($value, $i + 1), $m) === 1) {
                $alternatives = self::variable($m[0], '', '', $env);
                $i += strlen($m[0]);
            } else {
                // Compose refuses a lone `$`, so nothing runs with it.
                return null;
            }
            if ($alternatives === null) {
                return null;
            }

            $combined = [];
            foreach ($results as $prefix) {
                foreach ($alternatives as $alternative) {
                    $combined[] = $prefix . $literal . $alternative;
                }
            }
            $results = array_values(array_unique($combined));
            $literal = '';
            if (count($results) > self::MAX_RESULTS) {
                return null;
            }
        }

        return array_map(static fn (string $prefix): string => $prefix . $literal, $results);
    }

    /**
     * The values `${name<operator>word}` can take, once per state the variable
     * may be in. An `:?` error has none — Compose stops there.
     *
     * @param array<string, list<?string>|string> $env
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
            // A value in .env that interpolates again is not followed.
            if (is_string($set) && str_contains($set, '$')) {
                return null;
            }
            $nonEmpty = is_string($set) && $set !== '';
            $use = match ($operator) {
                '' => [$set ?? ''],
                ':-' => $nonEmpty ? [$set] : $wordValues(),
                '-' => $set !== null ? [$set] : $wordValues(),
                ':+' => $nonEmpty ? $wordValues() : [''],
                '+' => $set !== null ? $wordValues() : [''],
                ':?' => $nonEmpty ? [$set] : [],
                '?' => $set !== null ? [$set] : [],
                default => null,
            };
            if ($use === null) {
                return null;
            }
            array_push($values, ...$use);
        }

        return array_values(array_unique($values));
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
