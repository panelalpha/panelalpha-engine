<?php

namespace App\Lib\Hub;

use App\Models\Setting;
use App\System;

/**
 * Whether every app domain's vhost routes `/panelalpha-sso/hub` to the engine. A vhost gains the route only when it is
 * rendered again, so the engine renders them all once; until then the report tells the Hub no app can be logged into.
 */
final class HubSsoRoutes
{
    public const SETTING = 'hub_sso_routes_rendered';

    public static function areRendered(): bool
    {
        try {
            return Setting::get(self::SETTING) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return bool false when the webserver reload is still pending */
    public static function render(System $system): bool
    {
        $reloaded = $system->rebuildDomains();
        Setting::set(self::SETTING, '1');

        return $reloaded;
    }
}
