<?php

namespace App\System\Project;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Tunnel;
use App\System\Project as UserProject;

class Settings
{
    public const string KEY_CLOUDFLARE_API_TOKEN = 'cloudflare-api-token';

    /** @var list<string> */
    public const array KEYS = [
        self::KEY_CLOUDFLARE_API_TOKEN,
    ];

    /** @var list<string> */
    public const array SECRET_KEYS = [
        self::KEY_CLOUDFLARE_API_TOKEN,
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
     * @return array{key: string, value: ?string, set: bool, secret: bool, inherited: bool}
     */
    public function get(string $key): array
    {
        $key = self::normalizeKey($key);
        self::assertKnownKey($key);

        $own = $this->ownValue($key);
        $raw = $own ?? $this->rawValue($key);
        $secret = self::isSecret($key);

        return [
            'key' => $key,
            'value' => $raw === null ? null : ($secret ? self::redact($raw) : $raw),
            'set' => $raw !== null && $raw !== '',
            'secret' => $secret,
            // The project has none of its own and is using the engine-wide
            // secret ({@see \App\Lib\Vault\GlobalVault}). It works, but
            // clearing it here will not clear it -- that is an engine-level
            // change, not a project one.
            'inherited' => $own === null && $raw !== null && $raw !== '',
        ];
    }

    /**
     * @return list<array{key: string, value: ?string, set: bool, secret: bool, inherited: bool}>
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

        throw new \InvalidArgumentException("No unsetter for '{$key}'.");
    }

    /** The value in force, engine-wide fallback included. */
    private function rawValue(string $key): ?string
    {
        if ($key === self::KEY_CLOUDFLARE_API_TOKEN) {
            return $this->project->model()->getCloudflareApiToken();
        }

        return null;
    }

    /** Only what was set on this project, so `get()` can say which it is. */
    private function ownValue(string $key): ?string
    {
        if ($key === self::KEY_CLOUDFLARE_API_TOKEN) {
            return $this->project->model()->getOwnCloudflareApiToken();
        }

        return null;
    }
}
