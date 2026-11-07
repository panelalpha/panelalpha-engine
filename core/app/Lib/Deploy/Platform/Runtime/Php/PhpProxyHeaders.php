<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\TemplateLoader;

/**
 * Restores the https scheme, which TLS termination at the proxy hides from Apache, from the
 * forwarded headers or the container environment; prepended to every request.
 */
final class PhpProxyHeaders
{
    public const FILENAME = 'panelalpha-proxy.php';

    /**
     * Where the script lives in the shared base image; not /app, the customer's bind-mounted
     * directory, which they can delete over SFTP and a redeploy wipes.
     */
    public const IMAGE_DIR = '/usr/local/lib/panelalpha';

    public const IMAGE_PATH = self::IMAGE_DIR.'/'.self::FILENAME;

    public const INI_PATH = '/usr/local/etc/php/conf.d/panelalpha-proxy.ini';

    public static function script(): string
    {
        return TemplateLoader::asset('proxy-headers.php');
    }

    public static function ini(string $path): string
    {
        return "auto_prepend_file={$path}";
    }
}
