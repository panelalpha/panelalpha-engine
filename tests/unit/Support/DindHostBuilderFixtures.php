<?php

namespace Tests\Support;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;

trait DindHostBuilderFixtures
{
    private function builder(): DindHostBuilder
    {
        return new DindHostBuilder;
    }

    private function account(string $username, string $identity = '1001:1002'): EngineAccount
    {
        return new EngineAccount($username, '/home/'.$username, $identity);
    }
}
