<?php

namespace Tests\Support;

use App\Lib\Deploy\Engine\EngineAccount;

trait HostBuildTrustStoreFixtures
{
    private const HOST_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    private function account(): EngineAccount
    {
        return new EngineAccount('koel', '/home/koel', '1001:1002');
    }
}
