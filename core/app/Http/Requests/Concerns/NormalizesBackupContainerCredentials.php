<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Vault\RequestVault;
use App\Models\BackupContainer;
use App\System\EnginePaths;
use Illuminate\Validation\ValidationException;

trait NormalizesBackupContainerCredentials
{
    /**
     * Read a `vault:<key>` in each secret key, typed by the key's name. A backup
     * route has no project, so only a global entry can be used.
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $secretKeys
     * @return array<string, mixed>
     */
    private function resolveCredentialSecrets(array $credentials, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            if (! array_key_exists($key, $credentials)) {
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
     * Where backups survive a recreate and the root broker writes them: below the engine's directory it writes only
     * the engine's own files, so a store there would fail on its first backup.
     */
    public function assertLocalLocationIsSafe(string $location): void
    {
        $normalized = self::normalizedLocation($location);
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
        if (self::isAtOrBelow($normalized, EnginePaths::ENGINE_DIR)) {
            throw ValidationException::withMessages([
                'location' => 'Local backup location must not be inside the engine\'s directory ('.EnginePaths::ENGINE_DIR
                    .'): only the engine\'s own files may be written there.',
            ]);
        }
        if (in_array($normalized, self::PERSISTENT_LOCATION_ROOTS, true)) {
            throw ValidationException::withMessages([
                'location' => 'Local backup location must be a directory below '.implode(', ', self::PERSISTENT_LOCATION_ROOTS)
                    .', not one of those paths itself, which the engine depends on.',
            ]);
        }
        if (self::isUnderPersistentRoot($normalized)) {
            return;
        }

        throw ValidationException::withMessages([
            'location' => 'Local backup location must be under a path this engine persists ('
                .implode(', ', self::PERSISTENT_LOCATION_ROOTS)
                .'); anywhere else it lives inside the engine container and is lost when that container is recreated.',
        ]);
    }

    /** The directory a location names: backslashes as slashes, without empty or `.` parts, so a respelling is checked too. */
    private static function normalizedLocation(string $location): string
    {
        $path = str_replace('\\', '/', trim($location));
        $parts = array_filter(explode('/', $path), static fn (string $part): bool => $part !== '' && $part !== '.');

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $parts);
    }

    private static function isUnderPersistentRoot(string $path): bool
    {
        foreach (self::PERSISTENT_LOCATION_ROOTS as $root) {
            if (self::isAtOrBelow($path, $root)) {
                return true;
            }
        }

        return false;
    }

    private static function isAtOrBelow(string $path, string $root): bool
    {
        return str_starts_with($path.'/', $root.'/');
    }

    /**
     * @param  ?array<string, mixed>  $credentials
     * @return ?array<string, mixed>
     */
    public function normalizeCredentials(string $driver, ?array $credentials): ?array
    {
        if ($driver === 'local') {
            return null;
        }

        if (! is_array($credentials)) {
            throw ValidationException::withMessages([
                'credentials' => 'Credentials are required for non-local backup storage.',
            ]);
        }

        $keys = BackupContainer::CREDENTIAL_KEYS[$driver] ?? throw ValidationException::withMessages([
            'driver' => 'Unsupported backup storage driver.',
        ]);

        $this->requireCredentialKeys($credentials, $keys['required'], 'credentials');
        $credentials = $this->resolveCredentialSecrets($credentials, $keys['secrets']);

        return self::keptCredentials($credentials, $keys['kept']);
    }

    /**
     * The kept keys in their listed order, without the null or empty ones.
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $keys
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
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $keys
     */
    private function requireCredentialKeys(array $credentials, array $keys, string $field): void
    {
        $messages = [];
        foreach ($keys as $key) {
            $value = $credentials[$key] ?? null;
            if (! is_string($value) || $value === '') {
                $messages["{$field}.{$key}"] = "The {$key} field is required.";
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
