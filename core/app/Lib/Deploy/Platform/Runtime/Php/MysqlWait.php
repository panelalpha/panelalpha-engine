<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Template\Template;

/**
 * Blocks until DB_HOST accepts connections, up to two minutes; handed to the entrypoint by the
 * engine, which knows whether there is a database, rather than declared in laravel.yaml.
 */
final class MysqlWait
{
    public static function command(string $php = 'php'): string
    {
        return rtrim(Template::named('script/wait-for-mysql')->render(['php' => $php]), "\n");
    }
}
