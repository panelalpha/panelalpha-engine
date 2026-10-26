<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\TemplateLoader;

/**
 * What Apache cannot work out for itself: that the request arrived over https.
 *
 * TLS terminates at the engine's proxy, so the app container sees plain HTTP.
 * Prepended to every request, this restores the scheme from the forwarded
 * headers, or from the container environment when a request carries none.
 * Installed through auto_prepend_file: Apache resolves paths itself.
 */
final class PhpProxyHeaders
{
    public const FILENAME = 'panelalpha-proxy.php';

    /**
     * Where the script lives in the shared base image.
     *
     * Not /app: that is the customer's own bind-mounted directory, which they
     * can delete over SFTP and a redeploy wipes.
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
