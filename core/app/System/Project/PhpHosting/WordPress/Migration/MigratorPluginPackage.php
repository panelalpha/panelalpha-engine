<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * The migrator plugin for a manual_plugin migration: a new migration token
 * and a signed link, valid for a day, that downloads the plugin with that
 * token in its config.php. The link carries the token encrypted.
 */
final class MigratorPluginPackage
{
    public const ROUTE = 'wordpress.migrations.plugin-package.download';

    public const HOURS = 24;

    /**
     * @return array{token: string, download_url: string, expires_at: string}
     */
    public function mint(): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(self::HOURS);

        return [
            'token' => $token,
            'download_url' => URL::temporarySignedRoute(
                self::ROUTE,
                $expiresAt,
                ['package' => Crypt::encryptString($token)],
            ),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
