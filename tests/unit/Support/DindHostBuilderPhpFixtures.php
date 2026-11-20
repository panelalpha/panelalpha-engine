<?php

namespace Tests\Support;

use App\Lib\Deploy\Engine\EngineAccount;

trait DindHostBuilderPhpFixtures
{
    private const IMAGE = 'panelalpha/php:8.3-apache-bookworm-pa9519f31e';

    private function account(): EngineAccount
    {
        return new EngineAccount('acme', '/home/acme', '1001:1001');
    }
}
