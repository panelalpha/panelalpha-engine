<?php

namespace App\Lib\Deploy\Source;

/**
 * High-confidence shape checks for a Git access token, before it is used; no message ever holds the value.
 * A `vault:<key>` is resolved after validation, so only its shape is checked here.
 */
final class GitTokenInput
{
    public const MAX_LENGTH = 2048;

    /**
     * Total length for prefixes the forge documents, checked as a floor: shorter is a truncated paste.
     *
     * @var array<string, int>
     */
    private const MIN_LENGTH_BY_PREFIX = [
        'ghp_' => 40, 'gho_' => 40, 'ghu_' => 40, 'ghs_' => 40, 'ghr_' => 40,
        'glpat-' => 26,
    ];

    /** Header forms pasted in place of the credential. */
    private const AUTH_PREFIXES = ['bearer ', 'token ', 'basic ', 'authorization:'];

    private const VAULT_PREFIX = 'vault:';

    /** Trimmed rather than reported: a token never carries surrounding space. */
    public static function normalise(string $raw): string
    {
        return trim($raw);
    }

    public static function isVaultReference(string $value): bool
    {
        return str_starts_with($value, self::VAULT_PREFIX);
    }

    /** @return ?array<string, mixed> */
    public static function problem(string $field, string $raw): ?array
    {
        $value = self::normalise($raw);

        if ($value === '') {
            return self::problemOf($field, 'blank', 'A Git token must not be blank.');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            return self::problemOf($field, 'too_long',
                'A Git token must be at most '.self::MAX_LENGTH.' characters.');
        }
        if (self::isVaultReference($value)) {
            return ctype_alnum(substr($value, strlen(self::VAULT_PREFIX)))
                ? null
                : self::problemOf($field, 'malformed_vault_reference',
                    'A vault reference is `vault:<key>`, as the vault returned it when the secret was created.');
        }

        return self::pasteProblem($field, $value) ?? self::truncationProblem($field, $value);
    }

    /**
     * What came along with the paste; the header check precedes the space check, since a header has a space.
     *
     * @return ?array<string, mixed>
     */
    private static function pasteProblem(string $field, string $value): ?array
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return self::problemOf($field, 'malformed',
                'A Git token must not contain control characters. This usually means a newline '
                .'came along with the paste.');
        }
        if (self::hasAuthPrefix($value)) {
            return self::problemOf($field, 'has_auth_prefix',
                'Send the token itself, not a whole Authorization header: drop everything '
                .'before the credential.');
        }
        if (preg_match('/\s/', $value) === 1) {
            return self::problemOf($field, 'malformed',
                'A Git token must not contain spaces. Send the credential on its own.');
        }
        if (str_contains($value, '://')) {
            return self::problemOf($field, 'looks_like_url',
                'This looks like a URL, not a token. Send the repository address separately from the token.');
        }
        if (self::isQuoted($value)) {
            return self::problemOf($field, 'quoted',
                'The token is wrapped in quotes. Send it without them -- a shell kept them.');
        }

        return null;
    }

    /** First few characters, so two failures can be told apart in a log. */
    public static function redact(string $raw): string
    {
        $value = self::normalise($raw);

        return match (true) {
            $value === '' => '(blank)',
            self::isVaultReference($value) => 'vault:…',
            strlen($value) <= 8 => str_repeat('•', strlen($value)),
            default => substr($value, 0, 4).str_repeat('•', 8),
        };
    }

    /** @return ?array<string, mixed> */
    private static function truncationProblem(string $field, string $value): ?array
    {
        foreach (self::MIN_LENGTH_BY_PREFIX as $prefix => $minLength) {
            if (! str_starts_with($value, $prefix) || strlen($value) >= $minLength) {
                continue;
            }
            $forge = str_starts_with($prefix, 'glpat') ? 'GitLab' : 'GitHub';

            return self::problemOf($field, 'truncated',
                "This starts like a {$forge} token but is ".strlen($value).' characters, '
                ."short of the {$minLength} one has. The paste was probably cut off.");
        }

        return null;
    }

    private static function hasAuthPrefix(string $value): bool
    {
        $lower = strtolower($value);
        foreach (self::AUTH_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isQuoted(string $value): bool
    {
        if (strlen($value) < 2) {
            return false;
        }
        $first = $value[0];

        return ($first === '"' || $first === "'") && $first === $value[strlen($value) - 1];
    }

    /** @return array<string, mixed> */
    private static function problemOf(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $field.'_'.$code, 'message' => $message];
    }
}
