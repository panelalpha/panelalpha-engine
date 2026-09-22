<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\TemplateLoader;

/**
 * The php.ini the official image deliberately does not ship.
 *
 * `php:*` leaves php.ini-development and php.ini-production in $PHP_INI_DIR
 * as templates and loads neither, expecting the downstream image to choose.
 * Nothing here chose, so every account ran on PHP's compiled-in defaults:
 * display_errors on, expose_php on, error_reporting E_ALL -- notices in the
 * response body of every deployed site, and a 500 for any app whose first
 * notice fires before it starts a session.
 *
 * A conf.d file rather than `mv php.ini-production php.ini`. That template
 * would change more than the disclosure: it spells variables_order "GPCS",
 * dropping the `E` that puts the container environment -- which is how the
 * engine hands a project most of what it knows -- into $_SERVER and $_ENV.
 * It also leaves expose_php On, so it does not even fix the header.
 *
 * No Laravel dependencies -- unit-testable.
 */
final class PhpIniDefaults
{
    /**
     * conf.d, so an app keeps every way it has of overriding a directive:
     * .user.ini, `php_flag` in .htaccess, ini_set() at runtime.
     */
    public const INI_PATH = '/usr/local/etc/php/conf.d/panelalpha-defaults.ini';

    public const ASSET = 'php-defaults.ini';

    public static function ini(): string
    {
        return rtrim(TemplateLoader::asset(self::ASSET));
    }
}
