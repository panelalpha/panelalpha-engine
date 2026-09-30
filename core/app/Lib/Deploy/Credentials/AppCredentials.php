<?php

namespace App\Lib\Deploy\Credentials;

/**
 * The values behind a {@see CredentialSpec}: generated once, kept on the
 * project (`details['app_credentials']`, encrypted), rewritten into the account
 * on every deploy, and returned by GET /projects/{username}/app-credentials.
 *
 * Stored shape: `{fields: {NAME: {kind, value}}, login_path, created_at}`.
 * No Laravel dependencies.
 */
final class AppCredentials
{
    /** Relative to the account home: outside ~/project, which every deploy wipes. */
    public const ENV_FILE = '.panelalpha/app-credentials.env';

    public const PASSWORD_LENGTH = 24;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const SYMBOLS = '-_!';

    /** Shorter secrets are not masked in logs: they would garble every line. */
    public const MIN_MASKED_LENGTH = 4;

    /**
     * The stored credentials after this deploy: stored values kept, a newly
     * declared field generated (or adopted from `adopt_from`), the project's
     * own env_vars winning, and fields no longer declared dropped.
     *
     * @param ?array<string, mixed> $stored
     * @param array<string, string> $envVars
     * @param \Closure(string): ?array<string, string> $readAdopt the dotenv at a home-relative path, or null
     *        — asked for {@see ENV_FILE} first: what the engine delivered before survives a lost record
     * @return array{stored: ?array<string, mixed>, generated: list<string>, adopted: list<string>, overridden: list<string>}
     */
    public static function reconcile(
        ?CredentialSpec $spec,
        ?array $stored,
        array $envVars,
        \Closure $readAdopt,
        ?string $now = null,
        ?string $publicHost = null,
        ?string $projectEmail = null
    ): array {
        $result = ['stored' => null, 'generated' => [], 'adopted' => [], 'overridden' => []];
        if ($spec === null) {
            return $result;
        }

        $previous = is_array($stored['fields'] ?? null) ? $stored['fields'] : [];
        $adoptable = null;
        $fields = [];
        foreach ($spec->fields as $name => $field) {
            $kind = $field['kind'];
            $kept = $previous[$name] ?? null;
            $override = (string) ($envVars[$name] ?? '');
            if ($override !== '' && self::deliverable($override)) {
                $value = $override;
                $result['overridden'][] = $name;
            } elseif (is_array($kept) && ($kept['kind'] ?? null) === $kind
                && is_string($kept['value'] ?? null) && self::deliverable($kept['value'])) {
                $value = $kept['value'];
            } else {
                // First time this field is stored: the app may already be seeded with a
                // value, in the file the engine wrote or in the recipe's own older one.
                $adoptable ??= ($readAdopt(self::ENV_FILE) ?? [])
                    + ($spec->adoptFrom === null ? [] : ($readAdopt($spec->adoptFrom) ?? []));
                $found = (string) ($adoptable[$name] ?? '');
                if ($found !== '' && self::deliverable($found)) {
                    $value = $found;
                    $result['adopted'][] = $name;
                } else {
                    $value = $kind === CredentialSpec::KIND_PASSWORD
                        ? self::generatePassword($field['symbol'])
                        : self::filled($field['value'] ?? CredentialSpec::DEFAULTS[$kind], $publicHost, $projectEmail);
                    $result['generated'][] = $name;
                }
            }
            $fields[$name] = ['kind' => $kind, 'value' => $value];
        }

        $result['stored'] = [
            'fields' => $fields,
            'login_path' => $spec->loginPath,
            'created_at' => is_string($stored['created_at'] ?? null) ? $stored['created_at'] : ($now ?? date('c')),
        ];

        return $result;
    }

    /** A declared value with its placeholders filled; stored, so filled only once. */
    private static function filled(string $value, ?string $publicHost, ?string $projectEmail): string
    {
        // An address the env file cannot carry (a quote is legal in an email) falls back too.
        $email = $projectEmail !== null && $projectEmail !== '' && self::deliverable($projectEmail)
            ? $projectEmail
            : 'admin-{random}@{host}';
        $value = str_replace('{email}', $email, $value);

        return str_replace(
            ['{random}', '{host}'],
            [bin2hex(random_bytes(4)), ($publicHost !== null && $publicHost !== '') ? strtolower($publicHost) : 'example.com'],
            $value
        );
    }

    /**
     * 24 characters of [A-Za-z0-9] with an upper, a lower and a digit; `$symbol`
     * adds one of -_! anywhere but first, where a CLI would read it as an option.
     */
    public static function generatePassword(bool $symbol = false): string
    {
        do {
            $password = '';
            for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
                $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1);

        if ($symbol) {
            $at = random_int(1, self::PASSWORD_LENGTH);
            $password = substr($password, 0, $at) . self::SYMBOLS[random_int(0, strlen(self::SYMBOLS) - 1)] . substr($password, $at);
        }

        return $password;
    }

    /**
     * Single-quoted: literal to Compose's env_file reader and to `. file` in sh,
     * which is why a value with a quote or a newline is never delivered.
     *
     * @param array<string, mixed> $stored
     */
    public static function envFile(array $stored): string
    {
        $lines = ['# Written by the engine on every deploy; GET /projects/{name}/app-credentials returns it.'];
        foreach (self::fields($stored) as $name => $field) {
            $lines[] = $name . "='" . $field['value'] . "'";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The values a log must never show: the passwords. A username is public by nature.
     *
     * @param ?array<string, mixed> $stored
     * @return list<string>
     */
    public static function secretValues(?array $stored): array
    {
        $values = [];
        foreach (self::fields($stored) as $field) {
            if ($field['kind'] === CredentialSpec::KIND_PASSWORD && strlen($field['value']) >= self::MIN_MASKED_LENGTH) {
                $values[] = $field['value'];
            }
        }

        return $values;
    }

    /**
     * What GET /projects/{username} says: that credentials exist and where to get them. Never a value.
     *
     * @param ?array<string, mixed> $stored
     * @return array{available: bool, fields: list<array{name: string, kind: string}>, login_url: ?string, endpoint: string}
     */
    public static function pointer(?array $stored, ?string $publicUrl, string $username): array
    {
        $fields = [];
        foreach (self::fields($stored) as $name => $field) {
            $fields[] = ['name' => $name, 'kind' => $field['kind']];
        }

        return [
            'available' => $fields !== [],
            'fields' => $fields,
            'login_url' => $fields === [] ? null : self::loginUrl($publicUrl, $stored['login_path'] ?? null),
            'endpoint' => '/api/projects/' . $username . '/app-credentials',
        ];
    }

    /**
     * What GET /projects/{username}/app-credentials returns.
     *
     * @param ?array<string, mixed> $stored
     * @return array{available: bool, login_url: ?string, created_at: ?string, fields: list<array{name: string, kind: string, value: string}>}
     */
    public static function reveal(?array $stored, ?string $publicUrl): array
    {
        $fields = [];
        foreach (self::fields($stored) as $name => $field) {
            $fields[] = ['name' => $name, 'kind' => $field['kind'], 'value' => $field['value']];
        }
        if ($fields === []) {
            return ['available' => false, 'login_url' => null, 'created_at' => null, 'fields' => []];
        }

        return [
            'available' => true,
            'login_url' => self::loginUrl($publicUrl, $stored['login_path'] ?? null),
            'created_at' => is_string($stored['created_at'] ?? null) ? $stored['created_at'] : null,
            'fields' => $fields,
        ];
    }

    public static function loginUrl(?string $publicUrl, mixed $path): ?string
    {
        if ($publicUrl === null || $publicUrl === '') {
            return null;
        }

        return rtrim($publicUrl, '/') . (is_string($path) && $path !== '' ? $path : '/');
    }

    /**
     * The well-formed stored fields, whatever an older or hand-edited record holds.
     *
     * @param ?array<string, mixed> $stored
     * @return array<string, array{kind: string, value: string}>
     */
    private static function fields(?array $stored): array
    {
        $fields = [];
        foreach (is_array($stored['fields'] ?? null) ? $stored['fields'] : [] as $name => $field) {
            if (is_string($name) && is_array($field) && is_string($field['kind'] ?? null)
                && is_string($field['value'] ?? null) && self::deliverable($field['value'])) {
                $fields[$name] = ['kind' => $field['kind'], 'value' => $field['value']];
            }
        }

        return $fields;
    }

    private static function deliverable(string $value): bool
    {
        return $value !== '' && strpbrk($value, "'\n\r\0") === false;
    }
}
