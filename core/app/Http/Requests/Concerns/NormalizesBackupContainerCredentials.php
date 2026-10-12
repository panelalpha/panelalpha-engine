<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Vault\RequestVault;
use Illuminate\Validation\ValidationException;

trait NormalizesBackupContainerCredentials
{
    /**
     * Read a `vault:<id>` in each secret key, typed by the key's name. A backup
     * route has no project, so only a global entry can be used.
     *
     * @param array<string, mixed> $credentials
     * @param list<string> $secretKeys
     * @return array<string, mixed>
     */
    private function resolveCredentialSecrets(array $credentials, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            if (!array_key_exists($key, $credentials)) {
                continue;
            }
            $credentials[$key] = RequestVault::resolve($key, $credentials[$key], null);
        }

        return $credentials;
    }

    /**
     * Host paths core bind-mounts (docker-compose.yml): anywhere else is the
     * container's writable layer, which an engine update's recreate discards.
     * Not /var/tmp/panelalpha-backup: it is the staging scratch the cleanup
     * empties, and systemd-tmpfiles ages /var/tmp out.
     */
    public const PERSISTENT_LOCATION_ROOTS = [
        '/home',
        '/opt/panelalpha',
        '/var/www/html/storage',
    ];

    /**
     * Credential keys per remote driver: the ones that must be present, the ones
     * a `vault:<id>` may stand for, and every one kept.
     */
    private const CREDENTIAL_KEYS = [
        's3' => [
            'required' => ['access_key_id', 'secret_access_key'],
            'secrets' => ['secret_access_key'],
            'kept' => ['access_key_id', 'secret_access_key', 'region', 'endpoint', 'use_path_style_endpoint', 'prefix'],
        ],
        'ftp' => [
            'required' => ['host', 'username'],
            'secrets' => ['password'],
            'kept' => ['host', 'username', 'password', 'port'],
        ],
        'ftps' => [
            'required' => ['host', 'username'],
            'secrets' => ['password'],
            'kept' => ['host', 'username', 'password', 'port'],
        ],
        'sftp' => [
            'required' => ['host', 'username'],
            'secrets' => ['password', 'private_key', 'passphrase'],
            'kept' => ['host', 'username', 'password', 'private_key', 'passphrase', 'port'],
        ],
    ];

    public function assertLocalLocationIsSafe(string $location): void
    {
        $normalized = str_replace('\\', '/', trim($location));
        if ($normalized === '' || $normalized === '/' || $normalized === '/home') {
            throw ValidationException::withMessages([
                'location' => 'Local backup location must not be empty, filesystem root, or /home.',
            ]);
        }
        if (str_contains($normalized, "\0") || str_contains($normalized, '..')) {
            throw ValidationException::withMessages([
                'location' => 'Local backup location must not contain .. segments.',
            ]);
        }
        if (self::isUnderPersistentRoot(rtrim($normalized, '/'))) {
            return;
        }

        throw ValidationException::withMessages([
            'location' => 'Local backup location must be under a path this engine persists ('
                . implode(', ', self::PERSISTENT_LOCATION_ROOTS)
                . '); anywhere else it lives inside the engine container and is lost when that container is recreated.',
        ]);
    }

    private static function isUnderPersistentRoot(string $path): bool
    {
        foreach (self::PERSISTENT_LOCATION_ROOTS as $root) {
            if ($path === $root || str_starts_with($path . '/', $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ?array<string, mixed> $credentials
     * @return ?array<string, mixed>
     */
    public function normalizeCredentials(string $driver, ?array $credentials): ?array
    {
        if ($driver === 'local') {
            return null;
        }

        if (!is_array($credentials)) {
            throw ValidationException::withMessages([
                'credentials' => 'Credentials are required for non-local backup storage.',
            ]);
        }

        $keys = self::CREDENTIAL_KEYS[$driver] ?? throw ValidationException::withMessages([
            'driver' => 'Unsupported backup storage driver.',
        ]);

        $this->requireCredentialKeys($credentials, $keys['required'], 'credentials');
        $credentials = $this->resolveCredentialSecrets($credentials, $keys['secrets']);

        return self::keptCredentials($credentials, $keys['kept']);
    }

    /**
     * The kept keys in their listed order, without the null or empty ones.
     *
     * @param array<string, mixed> $credentials
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function keptCredentials(array $credentials, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            $kept[$key] = $credentials[$key] ?? null;
        }

        return array_filter($kept, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $credentials
     * @param list<string> $keys
     */
    private function requireCredentialKeys(array $credentials, array $keys, string $field): void
    {
        $messages = [];
        foreach ($keys as $key) {
            $value = $credentials[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $messages["{$field}.{$key}"] = "The {$key} field is required.";
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
