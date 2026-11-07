<?php

namespace App\Lib\Apis;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared transport for PanelAlpha cloud services (Connect and monitoring), so Integrations only
 * decide which host and which path.
 */
class PanelAlpha
{
    /**
     * Join `{base}/{path}` without a double slash, or '' (never a relative URL) when base is empty;
     * callers must check before posting.
     */
    public static function url(string $base, string $path): string
    {
        $base = rtrim(trim($base), '/');

        return $base === '' ? '' : $base.'/'.ltrim(trim($path), '/');
    }

    /** Header carrying this install's APP_UID. */
    public const APP_UID_HEADER = 'X-Engine-App-UID';

    /**
     * `[X-Engine-App-UID => uid]`, or [] when APP_UID is unset or not a header-safe token.
     * Every request to Connect or monitoring carries it.
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
     * JSON client with an optional Bearer from the engine's license key, for Connect's WithoutDNS proxy.
     * Monitoring ingest authenticates separately (telemetry.token).
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
