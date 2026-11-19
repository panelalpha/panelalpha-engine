<?php

namespace App\Lib\Deploy\Env;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\EnvFile;

/**
 * The secrets an env template describes in its comments, generated in the shape the comment asks for.
 * Atlas's `TOKEN_ENCRYPTION_KEY=CHANGE_ME` under "Must be exactly 64 hex characters" got the default 48
 * and its start aborted; Proval's `ENCRYPTION_KEY="ABCD1234=" # openssl rand -base64 32` was kept as it is;
 * Chiban's `{"k1":"REPLACE_WITH_BASE64_32_BYTES"}` kept its blank inside the JSON; Pitot comments every
 * entry out, though its local sign-in "requires PITOT_AUTH_CODE_SECRET (32+ characters)".
 *
 * A published placeholder whose comment only shows a command keeps the default the engine has always
 * given it, so a redeploy does not change a key an app already encrypts with. Passwords are a person's
 * answer and are left alone, and so is a third-party credential unless the comment says how to make it.
 *
 * No Laravel dependencies — unit-testable.
 */
final class TemplateSecretHints
{
    /** Laravel's key, which has its own format and is generated whatever the template says. */
    private const APP_KEY = 'APP_KEY';

    private const PASSWORD_PATTERN = '/(^|_)(PASSWORD|PASSWD|PASS)(_|$)/i';

    private const COMMENTED_ASSIGNMENT = '/^\s*#\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/';

    private const EMBEDDED_TOKEN = '/[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+/';

    private const MAX_DESCRIPTION_LINES = 10;

    /**
     * @param  array<string, string>  $overrides  keys the account set, which are a person's answer
     * @return array{0: string, 1: array<string, string>} contents, key => the format it was given
     */
    public static function fill(string $contents, string $seed, array $overrides = []): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $rows = EnvFile::parse($contents);
        $generated = self::forVariables($rows, $lines, $seed, $overrides)
            + self::forCommentedRequiredKeys($rows, $contents, $seed, $overrides);
        if ($generated === []) {
            return [$contents, []];
        }

        return [
            EnvFile::merge($contents, array_map(static fn (array $g): string => $g[0], $generated)),
            array_map(static fn (array $g): string => $g[1], $generated),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $lines
     * @param  array<string, string>  $overrides
     * @return array<string, array{0: string, 1: string}>
     */
    private static function forVariables(array $rows, array $lines, string $seed, array $overrides): array
    {
        $generated = [];
        foreach ($rows as $i => $row) {
            $key = ($row['type'] ?? null) === 'variable' ? (string) ($row['key'] ?? '') : '';
            if ($key === '' || isset($overrides[$key])) {
                continue;
            }
            $value = (string) ($row['value'] ?? '');
            $text = self::description($rows, $i).' '.self::inlineComment($lines[$i] ?? '');
            if (! self::isEligible($key, $text)) {
                continue;
            }
            $result = self::valueFor($key, $value, $text, $seed);
            if ($result !== null) {
                $generated[$key] = $result;
            }
        }

        return $generated;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function valueFor(string $key, string $value, string $text, string $seed): ?array
    {
        if (str_contains($value, '$')) {
            return null;
        }
        if ($value === '') {
            return self::forBlank($key, $text, $seed);
        }
        $embedded = self::withEmbeddedBlanksFilled($key, $value, $text, $seed);
        if ($embedded !== null) {
            return [$embedded, 'the blank inside it filled'];
        }

        return self::forValue($key, $value, $text, $seed);
    }

    /**
     * An empty key gets a value only when the comment asks for one and does not call it optional.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function forBlank(string $key, string $text, string $seed): ?array
    {
        $format = SecretFormat::fromText($text);
        if ($format === null || SecretFormat::isOptional($text)
            || (! $format->isRequired() && ! $format->hasGenerationRecipe())
        ) {
            return null;
        }

        return [$format->generate($key, $seed), $format->describe()];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function forValue(string $key, string $value, string $text, string $seed): ?array
    {
        $isBlank = ComposePlaceholders::isTemplatePlaceholder($value);
        $format = SecretFormat::fromText($isBlank ? $text.' '.$value : $text);
        if ($format === null || $format->isSatisfiedBy(trim($value))) {
            return null;
        }
        if (ComposePlaceholders::isPublishedSecret($key, $value) && ! $format->isRequired()) {
            return null;
        }

        return [$format->generate($key, $seed), $format->describe()];
    }

    /**
     * A fill-in blank inside a value (`{"k1":"REPLACE_WITH_BASE64_32_BYTES"}`), each replaced in the
     * shape its own words or the comment ask for; null when there is none.
     */
    private static function withEmbeddedBlanksFilled(string $key, string $value, string $text, string $seed): ?string
    {
        if (ComposePlaceholders::isTemplatePlaceholder($value)) {
            return null;
        }
        $filled = false;
        $result = preg_replace_callback(
            self::EMBEDDED_TOKEN,
            static function (array $m) use ($key, $text, $seed, &$filled): string {
                if (! ComposePlaceholders::isTemplatePlaceholder($m[0]) || ! ComposePlaceholders::isDistinctiveToken($m[0])) {
                    return $m[0];
                }
                $filled = true;
                $format = SecretFormat::fromText($m[0]) ?? SecretFormat::fromText($text);

                return $format === null
                    ? ComposePlaceholders::generatedSecret($key.':'.$m[0], $seed)
                    : $format->generate($key.':'.$m[0], $seed);
            },
            $value
        );

        return $filled && is_string($result) ? $result : null;
    }

    /**
     * `# KEY=` a template leaves commented out although its text says the app requires KEY.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $overrides
     * @return array<string, array{0: string, 1: string}>
     */
    private static function forCommentedRequiredKeys(array $rows, string $contents, string $seed, array $overrides): array
    {
        $active = self::activeKeys($rows);
        $generated = [];
        foreach ($rows as $i => $row) {
            if (($row['type'] ?? null) !== 'comment'
                || preg_match(self::COMMENTED_ASSIGNMENT, (string) ($row['text'] ?? ''), $m) !== 1
            ) {
                continue;
            }
            $key = $m[1];
            if (isset($active[$key]) || isset($overrides[$key]) || isset($generated[$key])
                || trim($m[2]) !== '' || $key === self::APP_KEY || ! ComposePlaceholders::namesOwnSecret($key)
                || preg_match(self::PASSWORD_PATTERN, $key) === 1 || ! self::isRequiredBy($contents, $key)
            ) {
                continue;
            }
            $format = SecretFormat::fromText(self::description($rows, $i).' '.self::requirementLines($contents, $key));
            $generated[$key] = $format === null
                ? [ComposePlaceholders::generatedSecret($key, $seed), 'required by the template']
                : [$format->generate($key, $seed), $format->describe()];
        }

        return $generated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, true>
     */
    private static function activeKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (($row['type'] ?? null) === 'variable' && ($row['key'] ?? '') !== '') {
                $keys[(string) $row['key']] = true;
            }
        }

        return $keys;
    }

    private static function isRequiredBy(string $contents, string $key): bool
    {
        return self::requirementLines($contents, $key) !== '';
    }

    /** The comment lines saying the app requires $key. */
    private static function requirementLines(string $contents, string $key): string
    {
        $k = preg_quote($key, '/');
        preg_match_all(
            '/^\s*#.*(?:\brequires?\s+'.$k.'\b|\b'.$k.'\s+(?:is|are)\s+required\b|\b'.$k.'\s+must\s+be\s+set\b).*$/mi',
            $contents,
            $m
        );

        return implode(' ', $m[0]);
    }

    private static function isEligible(string $key, string $text): bool
    {
        return $key !== self::APP_KEY
            && ComposePlaceholders::isSecretKey($key)
            && preg_match(self::PASSWORD_PATTERN, $key) !== 1
            && (ComposePlaceholders::namesOwnSecret($key) || SecretFormat::hasRecipe($text));
    }

    /**
     * The comment block right above row $i, up to a blank line, a value, or another key commented out.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function description(array $rows, int $i): string
    {
        $lines = [];
        for ($j = $i - 1; $j >= 0 && count($lines) < self::MAX_DESCRIPTION_LINES; $j--) {
            $text = ($rows[$j]['type'] ?? null) === 'comment' ? (string) ($rows[$j]['text'] ?? '') : null;
            if ($text === null || preg_match(self::COMMENTED_ASSIGNMENT, $text) === 1) {
                break;
            }
            array_unshift($lines, $text);
        }

        return implode(' ', $lines);
    }

    private static function inlineComment(string $line): string
    {
        $equals = strpos($line, '=');
        if ($equals === false || preg_match('/\s#(.*)$/', substr($line, $equals + 1), $m) !== 1) {
            return '';
        }

        return $m[1];
    }
}
