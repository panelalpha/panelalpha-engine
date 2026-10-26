<?php

namespace App\Lib\Hub;

/**
 * Who this engine is to the Hub. APP_UID is the installer's random id, the one
 * every request to Connect already carries in X-Engine-App-UID. It names the
 * engine and proves nothing.
 */
final class EngineIdentity
{
    /** Which PanelAlpha product this is, so the Hub can tell it from others that connect the same way. */
    public const PRODUCT = 'engine';

    /** What the Hub accepts as an app_id. */
    private const HUB_APP_ID = '/\A[A-Za-z0-9._-]{8,128}\z/';

    public static function appId(): string
    {
        return trim((string) config('app.uid', ''));
    }

    public static function version(): string
    {
        return (string) config('system.version', '');
    }

    /** Null when the id is usable, else why not. */
    public static function appIdProblem(): ?string
    {
        $id = self::appId();
        if ($id === '') {
            return 'APP_UID is not set in .env-core, so this engine has no id to connect with.';
        }

        return preg_match(self::HUB_APP_ID, $id) === 1
            ? null
            : 'APP_UID must be 8-128 characters from A-Z a-z 0-9 . _ - to connect to PanelAlpha Connect.';
    }
}
