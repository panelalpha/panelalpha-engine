<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\OneShotServices;
use App\Lib\Deploy\Compose\ServiceReferences;
use PHPUnit\Framework\TestCase;

/** A service another one joins with `network_mode: service:` has to keep running. */
class OneShotServicesNetworkModeTest extends TestCase
{
    public function test_a_file_setup_service_another_joins_the_network_of_is_no_one_shot(): void
    {
        $perms = ['image' => 'alpine', 'command' => 'chown -R 1000:1000 /data'];

        $this->assertSame(['perms'], OneShotServices::in(['services' => ['perms' => $perms, 'app' => ['image' => 'acme/app']]]));
        $this->assertSame([], OneShotServices::in(['services' => [
            'perms' => $perms,
            'app' => ['image' => 'acme/app', 'network_mode' => 'service:perms'],
        ]]));
    }

    public function test_an_entry_that_is_not_a_service_is_never_needed(): void
    {
        $this->assertSame(['db'], ServiceReferences::needed([
            'x-common' => 'anchor',
            'app' => ['image' => 'acme/app', 'depends_on' => ['db', 'x-common']],
            'db' => ['image' => 'postgres:16'],
        ]));
    }
}
