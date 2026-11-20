<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\Webserver\NginxProxy;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebserverFixtures;

class WebserverTest extends TestCase
{
    use WebserverFixtures;

    public function test_nginx_proxy_vhost_path_is_hardcoded_not_current_driver(): void
    {
        $system = new System();
        $driver = new NginxProxy($system);

        $this->assertSame(
            '/opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/vhosts',
            $driver->domainsConfigsDirPath()
        );
        $this->assertFalse($driver->domainConfigExists('no-such-domain.example'));
        $this->assertFalse($system->webserver()->domainExists('no-such-domain.example'));
    }
}
