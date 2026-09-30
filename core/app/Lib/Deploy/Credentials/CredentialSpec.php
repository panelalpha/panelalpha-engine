<?php

namespace App\Lib\Deploy\Credentials;

use App\Lib\Deploy\Platform\ManifestException;

/**
 * A manifest's `credentials:` block: the login an application is seeded with,
 * which the engine generates, keeps on the project and delivers to the app.
 *
 * Only the declaration. {@see AppCredentials} turns it into values.
 */
final class CredentialSpec
{
    public const KIND_USERNAME = 'username';
    public const KIND_EMAIL = 'email';
    public const KIND_PASSWORD = 'password';

    public const KINDS = [self::KIND_USERNAME, self::KIND_EMAIL, self::KIND_PASSWORD];

    /** What a username or email field is when the manifest names no value. */
    public const DEFAULTS = [
        self::KIND_USERNAME => 'admin',
        self::KIND_EMAIL => 'admin@example.com',
    ];

    private const KEYS = ['login_path', 'adopt_from', 'fields'];

    private const FIELD_KEYS = ['kind', 'value', 'symbol'];

    private const ENV_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    // Written unquoted-safe into an env file compose and sh both read.
    private const LITERAL = '/^[A-Za-z0-9@._+\-]+$/';

    /**
     * Filled once, when the value is first stored: 8 random hex characters, the
     * account's domain, the project's email (or admin-{random}@{host} without one).
     */
    public const PLACEHOLDERS = ['{random}', '{host}', '{email}'];

    /**
     * @param array<string, array{kind: string, value: ?string, symbol: bool}> $fields
     */
    private function __construct(
        public readonly array $fields,
        public readonly ?string $loginPath,
        public readonly ?string $adoptFrom,
    ) {
    }

    /**
     * @param \Closure(string): ManifestException $fail builds the error, naming the manifest
     * @throws ManifestException
     */
    public static function parse(mixed $raw, \Closure $fail): ?self
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw $fail('credentials must be a mapping with `fields`');
        }
        $unknown = array_diff(array_keys($raw), self::KEYS);
        if ($unknown !== []) {
            throw $fail('credentials: unknown key(s) ' . implode(', ', $unknown) . '; expected ' . implode(', ', self::KEYS));
        }

        $declared = $raw['fields'] ?? null;
        if (!is_array($declared) || $declared === [] || array_is_list($declared)) {
            throw $fail('credentials.fields must be a non-empty mapping of env name => field');
        }
        $fields = [];
        foreach ($declared as $name => $field) {
            $fields[(string) $name] = self::parseField((string) $name, $field, $fail);
        }

        return new self($fields, self::loginPath($raw['login_path'] ?? null, $fail), self::adoptFrom($raw['adopt_from'] ?? null, $fail));
    }

    /**
     * Back from the decision array a manifest describes itself as.
     *
     * @param array<string, mixed>|null $array
     */
    public static function fromArray(?array $array): ?self
    {
        if ($array === null || !is_array($array['fields'] ?? null) || $array['fields'] === []) {
            return null;
        }

        return self::parse($array, static fn (string $m): ManifestException => new ManifestException("credentials: {$m}"));
    }

    /** @return array{login_path: ?string, adopt_from: ?string, fields: array<string, array<string, mixed>>} */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = array_filter(
                ['kind' => $field['kind'], 'value' => $field['value'], 'symbol' => $field['symbol'] ?: null],
                static fn (mixed $v): bool => $v !== null
            );
        }

        return ['login_path' => $this->loginPath, 'adopt_from' => $this->adoptFrom, 'fields' => $fields];
    }

    /**
     * @param \Closure(string): ManifestException $fail
     * @return array{kind: string, value: ?string, symbol: bool}
     */
    private static function parseField(string $name, mixed $field, \Closure $fail): array
    {
        if (preg_match(self::ENV_NAME, $name) !== 1) {
            throw $fail("credentials.fields: '{$name}' is not a valid environment variable name");
        }
        if (!is_array($field) || array_is_list($field)) {
            throw $fail("credentials.fields.{$name} must be a mapping with a `kind`");
        }
        $unknown = array_diff(array_keys($field), self::FIELD_KEYS);
        if ($unknown !== []) {
            throw $fail("credentials.fields.{$name}: unknown key(s) " . implode(', ', $unknown));
        }
        $kind = $field['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw $fail("credentials.fields.{$name}.kind must be one of " . implode(', ', self::KINDS));
        }

        $value = $field['value'] ?? null;
        if ($value !== null && $kind === self::KIND_PASSWORD) {
            throw $fail("credentials.fields.{$name}: a password is generated, it cannot declare a value");
        }
        if ($value !== null && (!is_string($value)
            || preg_match(self::LITERAL, str_replace(self::PLACEHOLDERS, 'x', $value)) !== 1)) {
            throw $fail(
                "credentials.fields.{$name}.value must be a non-empty string of letters, digits and @._+-"
                . ', optionally with ' . implode(' / ', self::PLACEHOLDERS)
            );
        }

        $symbol = $field['symbol'] ?? false;
        if (!is_bool($symbol)) {
            throw $fail("credentials.fields.{$name}.symbol must be true or false");
        }
        if ($symbol && $kind !== self::KIND_PASSWORD) {
            throw $fail("credentials.fields.{$name}.symbol applies to a password only");
        }

        return ['kind' => $kind, 'value' => $value, 'symbol' => $symbol];
    }

    /** @param \Closure(string): ManifestException $fail */
    private static function loginPath(mixed $raw, \Closure $fail): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || !str_starts_with($raw, '/') || preg_match('/\s/', $raw) === 1) {
            throw $fail('credentials.login_path must be a path starting with /');
        }

        return $raw;
    }

    /**
     * Relative to the account home, and never out of it: the file is read as root.
     *
     * @param \Closure(string): ManifestException $fail
     */
    private static function adoptFrom(mixed $raw, \Closure $fail): ?string
    {
        if ($raw === null) {
            return null;
        }
        $segments = is_string($raw) ? explode('/', trim($raw)) : [];
        $valid = is_string($raw) && !str_starts_with(trim($raw), '/') && $segments !== []
            && array_filter($segments, static fn (string $s): bool => $s === '' || $s === '.' || $s === '..') === [];
        if (!$valid) {
            throw $fail('credentials.adopt_from must be a relative path inside the account home');
        }

        return trim($raw);
    }
}
