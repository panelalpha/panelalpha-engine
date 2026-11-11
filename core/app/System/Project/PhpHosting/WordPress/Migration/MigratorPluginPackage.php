<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use DateTimeInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * A manual_plugin migration's new token and a day-long signed link to the plugin with that token in its config.php.
 * The link carries the token encrypted and names the engine's own address, opened from the user's own machine.
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
            'download_url' => self::signedDownload($expiresAt, Crypt::encryptString($token)),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Signed over the engine's own address from the configuration, not over the host the request arrived on: the
     * REST API and MCP then answer the same link, and it is reachable from wherever the user downloads the plugin.
     */
    private static function signedDownload(DateTimeInterface $expiresAt, string $package): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $scheme = str_starts_with($origin, 'http://') ? 'http' : 'https';
        URL::useOrigin($origin);
        URL::forceScheme($scheme);

        try {
            return URL::temporarySignedRoute(self::ROUTE, $expiresAt, ['package' => $package]);
        } finally {
            URL::useOrigin(null);
            URL::forceScheme(null);
        }
    }
}
