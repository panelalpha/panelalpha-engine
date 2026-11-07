<?php

namespace Tests\Unit\System;

use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\Nginx;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\WebserverInterface;
use Tests\TestCase;

/**
 * A port taken or freed reaches nginx-proxy through the engine's webserver, whatever its current driver is.
 */
class WebserverHttpsPortsTest extends TestCase
{
    public function test_a_changed_port_reaches_nginx_proxy_and_reloads(): void
    {
        $driver = new class extends NginxProxy {
            public int $applied = 0;

            public function __construct() {}

            public function applyHttpsPorts(): void
            {
                $this->applied++;
            }
        };
        $system = $this->system($driver);

        (new HttpsPortsChange($system, new PublicRoutingGrants))->listenersChanged();

        $this->assertSame(1, $driver->applied);
        $this->assertSame(1, $system->reloads);
    }

    public function test_another_webserver_has_no_ports_to_apply(): void
    {
        $system = $this->system(new class extends Nginx {
            public function __construct() {}
        });

        (new HttpsPortsChange($system, new PublicRoutingGrants))->listenersChanged();

        $this->assertSame(1, $system->reloads);
    }

    private function system(WebserverInterface $driver): System
    {
        return new class ($driver) extends System {
            public int $reloads = 0;

            public function __construct(private WebserverInterface $driver) {}

            public function webserver(): Webserver
            {
                return new class ($this, $this->driver) extends Webserver {
                    public function __construct(System $system, private WebserverInterface $fixed)
                    {
                        parent::__construct($system);
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->fixed;
                    }
                };
            }

            public function reloadWebserver(): bool
            {
                $this->reloads++;

                return true;
            }
        };
    }
}
