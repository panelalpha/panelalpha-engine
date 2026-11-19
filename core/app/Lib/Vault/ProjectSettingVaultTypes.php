<?php

namespace App\Lib\Vault;

use App\Models\SecretVaultEntry;
use App\System\Project\Settings;

/**
 * The vault type a secret project setting takes a `vault:<key>` for. A setting outside the list can be read back
 * verbatim, so it never reads the vault and keeps `vault:...` as a literal.
 */
class ProjectSettingVaultTypes
{
    /** @var array<string, string> */
    public const array TYPES = [
        Settings::KEY_CLOUDFLARE_API_TOKEN => SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN,
        Settings::KEY_REGISTRY_AUTH => SecretVaultEntry::TYPE_REGISTRY_AUTH,
    ];

    public static function typeFor(string $key): ?string
    {
        return self::TYPES[Settings::normalizeKey($key)] ?? null;
    }
}
