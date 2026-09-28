<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\Template;

/**
 * The two files that make Apache serve a PHP application the way its author
 * assumed it would be served.
 *
 * The engine used to run PHP's built-in server behind its proxy. That server
 * documents itself as a development tool and behaves like one: it handles one
 * request at a time -- measured at 8s for four concurrent 2s requests, against
 * 2s under Apache -- and it has no concept of .htaccess. The second is the
 * expensive half. Matomo writes .htaccess denials into twelve directories
 * during installation; under the built-in server every one of them was a
 * no-op, and `/.git/config`, `/lang/en.json`, `/tmp/` and every `.php` file
 * under vendor/ answered 200.
 *
 * {@see \App\Lib\Deploy\Platform\Runtime\Php\PhpProxyHeaders} covers the one
 * thing the old router did that Apache cannot know about on its own.
 *
 * No Laravel dependencies -- unit-testable.
 */
final class PhpApacheConfig
{
    /** Debian's default site, replaced rather than added to. */
    public const VHOST_PATH = '/etc/apache2/sites-available/000-default.conf';

    public const PORTS_PATH = '/etc/apache2/ports.conf';

    /**
     * Modules an application expects to exist. `rewrite` is the front
     * controller of every PHP framework there is; `headers` is what a
     * .htaccess reaches for when it sets CSP or cache policy; `remoteip`
     * restores the visitor's address from the proxy's X-Real-IP.
     *
     * @var list<string>
     */
    public const MODULES = ['rewrite', 'headers', 'remoteip'];

    public static function vhost(int $port): string
    {
        return rtrim(Template::named('apache-vhost')->render(['port' => $port]));
    }

    public static function ports(int $port): string
    {
        return "Listen {$port}";
    }

    public static function modules(): string
    {
        return implode(' ', self::MODULES);
    }
}
