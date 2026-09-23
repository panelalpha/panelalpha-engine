<?php

namespace App\Lib\Apis;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared transport for PanelAlpha cloud services (Connect and monitoring).
 *
 * Join rules and the licensed HTTP client live here so Integrations only
 * decide *which* host and *which* path. Empty base yields '' rather than a
 * relative URL — callers must check before posting.
 */
class PanelAlpha
{
    /**
     * Join `{base}/{path}`, or '' when base is empty.
     *
     * Trailing slash on the base and leading slash on the path are stripped
     * so callers can pass either form without double-slashing.
     */
    public static function url(string $base, string $path): string
    {
        $base = rtrim(trim($base), '/');

        return $base === '' ? '' : $base . '/' . ltrim(trim($path), '/');
    }

    /** Header carrying this install's APP_UID. */
    public const APP_UID_HEADER = 'X-Engine-App-UID';

    /**
     * `[X-Engine-App-UID => uid]`, or [] when APP_UID is unset or not a
     * header-safe token. Every request to Connect or monitoring carries it.
     *
     * @return array<string, string>
     */
    public static function identityHeaders(): array
    {
        $uid = trim((string) config('app.uid', ''));

        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $uid) === 1
            ? [self::APP_UID_HEADER => $uid]
            : [];
    }

    /**
     * JSON client with optional Bearer from the engine's license key.
     *
     * Used by Connect's WithoutDNS proxy. Monitoring ingest authenticates
     * separately (telemetry.token) and does not go through this method.
     */
    public static function http(): PendingRequest
    {
        $pending = Http::acceptJson()->asJson()->timeout(60)->withHeaders(self::identityHeaders());
        $licenseKey = Setting::get('license_key');
        if (is_string($licenseKey) && trim($licenseKey) !== '') {
            $pending = $pending->withToken(trim($licenseKey));
        }

        return $pending;
    }
}
