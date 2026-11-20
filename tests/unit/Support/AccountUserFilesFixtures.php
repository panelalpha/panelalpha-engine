<?php

namespace Tests\Support;

use App\Lib\Deploy\Platform\Runtime\Php\AccountUserFiles;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

trait AccountUserFilesFixtures
{
    private const IMAGE = 'panelalpha/php:8.2-apache-bookworm-pa20260924';

    private const PASSWD = "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n";

    private const GROUP = "root:x:0:\nwww-data:x:33:\n";

    /** @var list<list<string>> */
    private array $calls = [];

    private function files(?\Closure $run = null, ?Repository $cache = null): AccountUserFiles
    {
        $run ??= function (array $argv): string {
            $this->calls[] = $argv;

            return end($argv) === '/etc/passwd' ? self::PASSWD : self::GROUP;
        };

        return new AccountUserFiles($run, $cache ?? new Repository(new ArrayStore));
    }
}
