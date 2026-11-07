<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\TemplateLoader;

/**
 * The php.ini the official image does not ship, so no site runs on compiled-in defaults
 * (display_errors on). No Laravel dependencies: unit-testable.
 */
final class PhpIniDefaults
{
    /**
     * conf.d rather than php.ini-production, whose variables_order drops the environment, and so an
     * app keeps .user.ini, `php_flag` in .htaccess and ini_set() overrides.
     */
    public const INI_PATH = '/usr/local/etc/php/conf.d/panelalpha-defaults.ini';

    public const ASSET = 'php-defaults.ini';

    public static function ini(): string
    {
        return rtrim(TemplateLoader::asset(self::ASSET));
    }
}
