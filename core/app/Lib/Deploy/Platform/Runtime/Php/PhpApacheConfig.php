<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\Template;

/**
 * The two files that make Apache serve PHP as its author assumed, unlike the built-in server it replaced
 * (one request at a time, .htaccess denials ignored). No Laravel dependencies: unit-testable.
 */
final class PhpApacheConfig
{
    /** Debian's default site, replaced rather than added to. */
    public const VHOST_PATH = '/etc/apache2/sites-available/000-default.conf';

    public const PORTS_PATH = '/etc/apache2/ports.conf';

    /**
     * Modules an application expects: `rewrite` for front controllers, `headers` for .htaccess
     * CSP and cache policy, `remoteip` for the visitor's address from X-Real-IP.
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
