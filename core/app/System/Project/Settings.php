<?php

namespace App\System\Project;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Deploy\Dind\RegistryAuth;
use App\Models\Tunnel;
use App\System\Project as UserProject;

class Settings
{
    public const string KEY_CLOUDFLARE_API_TOKEN = 'cloudflare-api-token';

    /** Private image registry logins, one `host username token` line each. */
    public const string KEY_REGISTRY_AUTH = 'registry-auth';

    /** Container paths kept on named volumes across deploys, comma-separated. */
    public const string KEY_PERSIST_PATHS = 'persist-paths';

    /** @var list<string> */
    public const array KEYS = [
        self::KEY_CLOUDFLARE_API_TOKEN,
        self::KEY_REGISTRY_AUTH,
        self::KEY_PERSIST_PATHS,
    ];

    /** @var list<string> */
    public const array SECRET_KEYS = [
        self::KEY_CLOUDFLARE_API_TOKEN,
        self::KEY_REGISTRY_AUTH,
    ];

    public function __construct(
        private readonly UserProject $project,
    ) {
    }

    public static function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    public static function assertKnownKey(string $key): void
    {
        $key = self::normalizeKey($key);
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException(
                "Unknown project setting '{$key}'. Known: " . implode(', ', self::KEYS)
            );
        }
    }

    public static function isSecret(string $key): bool
    {
        return in_array(self::normalizeKey($key), self::SECRET_KEYS, true);
    }

    public static function redact(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', max(4, $len));
        }

        return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
    }

    /**
     * @return array{key: string, value: ?string, set: bool, secret: bool}
     */
    public function get(string $key): array
    {
        $key = self::normalizeKey($key);
        self::assertKnownKey($key);

        $value = $this->storedValue($key);
        $secret = self::isSecret($key);

        return [
            'key' => $key,
            'value' => $value === null ? null : ($secret ? self::redact($value) : $value),
            'set' => $value !== null && $value !== '',
            'secret' => $secret,
        ];
    }

    /**
     * @return list<array{key: string, value: ?string, set: bool, secret: bool}>
     */
    public function list(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[] = $this->get($key);
        }

        return $out;
    }

    /**
     * @return array{message?: string, account_id?: string, account_name?: string}
     */
    public function set(string $key, string $value): array
    {
        $key = self::normalizeKey($key);
        self::assertKnownKey($key);

        $projectUser = $this->project->model();

        if ($key === self::KEY_CLOUDFLARE_API_TOKEN) {
            Cloudflare::assertDinD($projectUser);
            $account = Cloudflare::setApiToken($projectUser, $value);

            return [
                'message' => 'Cloudflare API token saved.',
                'account_id' => $account['account_id'],
                'account_name' => $account['account_name'],
            ];
        }

        if ($key === self::KEY_REGISTRY_AUTH) {
            $auth = RegistryAuth::parse($value);
            if ($auth->isEmpty()) {
                throw new \InvalidArgumentException("registry-auth needs at least one 'host username token' line.");
            }
            $projectUser->setDetails(['registry_auth' => trim($value)]);
            $projectUser->save();

            return ['message' => 'Registry logins saved for: ' . implode(', ', $auth->hosts()) . '.'];
        }

        if ($key === self::KEY_PERSIST_PATHS) {
            $paths = self::persistPaths($value);
            $projectUser->setDetails(['persist_paths' => $paths]);
            $projectUser->save();

            return ['message' => 'Kept across deploys from the next one: ' . implode(', ', $paths) . '.'];
        }

        throw new \InvalidArgumentException("No setter for '{$key}'.");
    }

    /**
     * @param bool $force When unsetting cloudflare-api-token with existing tunnels, tear them down
     */
    public function unset(string $key, bool $force = false): void
    {
        $key = self::normalizeKey($key);
        self::assertKnownKey($key);

        $projectUser = $this->project->model();

        if ($key === self::KEY_CLOUDFLARE_API_TOKEN) {
            if (Tunnel::projectHasCloudflareTunnels($projectUser) && !$force) {
                throw new CloudflareException(
                    "Project '{$projectUser->username}' still has Cloudflare tunnel hostname(s). "
                    . 'Delete them first, or pass --force to tear down Cloudflare tunnels and clear the token.'
                );
            }

            if (Tunnel::projectHasCloudflareTunnels($projectUser) || $projectUser->getCloudflareTunnelId() !== null) {
                Cloudflare::teardownProject($projectUser);
            } else {
                Cloudflare::clearConnectorState($projectUser);
            }

            return;
        }

        if ($key === self::KEY_REGISTRY_AUTH || $key === self::KEY_PERSIST_PATHS) {
            $details = $projectUser->getDetails();
            unset($details[str_replace('-', '_', $key)]);
            $projectUser->details = $details;
            $projectUser->save();

            return;
        }

        throw new \InvalidArgumentException("No unsetter for '{$key}'.");
    }

    /**
     * Absolute container paths, each a plain directory name a compose volume
     * can be mounted on.
     *
     * @return list<string>
     */
    public static function persistPaths(string $value): array
    {
        $paths = [];
        foreach (explode(',', $value) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $normal = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
            if (!str_starts_with($path, '/') || $normal === '/'
                || preg_match('#^(/[A-Za-z0-9._@+-]+)+$#', $normal) !== 1
                || preg_match('#/\.\.?(/|$)#', $normal) === 1) {
                throw new \InvalidArgumentException(
                    "persist-paths: '{$path}' is not an absolute path inside the container (e.g. /app/storage)."
                );
            }
            $paths[$normal] = $normal;
        }
        if ($paths === []) {
            throw new \InvalidArgumentException('persist-paths needs at least one absolute path, e.g. /app/storage,/app/public/uploads.');
        }

        return array_values($paths);
    }

    private function storedValue(string $key): ?string
    {
        if ($key === self::KEY_CLOUDFLARE_API_TOKEN) {
            return $this->project->model()->getCloudflareApiToken();
        }
        if ($key === self::KEY_REGISTRY_AUTH) {
            return $this->project->model()->getRegistryAuth();
        }
        if ($key === self::KEY_PERSIST_PATHS) {
            $paths = $this->project->model()->getPersistPaths();

            return $paths === [] ? null : implode(',', $paths);
        }

        return null;
    }
}
