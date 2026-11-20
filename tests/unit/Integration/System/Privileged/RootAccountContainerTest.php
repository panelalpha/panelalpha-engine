<?php

namespace Tests\Integration\System\Privileged;

use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

final class RootAccountContainerTest extends TestCase
{
    public function test_the_broker_takes_it_as_an_engine_api_call_it_makes_itself(): void
    {
        $exec = Privileged::accountContainer('alice')->exec(['true']);

        $this->assertSame(['pa-root', ...array_slice($exec, 1)], LocalRoot::plain($exec));
    }

    public function test_a_registry_refresh_is_no_host_request(): void
    {
        $container = Privileged::accountContainer('alice');

        $this->assertSame(['pa-root', 'account-container.daemonConfigMounted', '--', 'alice'], LocalRoot::plain($container->daemonConfigMounted()));
        $this->assertSame(['pa-root', 'account-container.reloadDaemon', '--', 'alice'], LocalRoot::plain($container->reloadDaemon()));
    }
}
