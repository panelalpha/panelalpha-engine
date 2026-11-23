<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Port\ComposePortScan;
use App\System\Project\Dind\LxcfsProc;
use PHPUnit\Framework\TestCase;

/** A stock web server's port on 80 does not lead over the port of the service the checkout builds. */
class ComposePortScanStockWebServerTest extends TestCase
{
    /** nginx-ui: the UI is built and answers on 8080, the nginx beside it shows its welcome page on 80. */
    public function test_the_built_service_leads_over_a_stock_nginx(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => [
            'nginx-ui' => ['build' => '.', 'image' => 'nginx-ui:latest', 'ports' => ['8080:8080'], 'volumes' => ['nginx:/etc/nginx']],
            'nginx' => ['image' => 'nginx:1.18.0-alpine', 'ports' => ['80:80'], 'volumes' => ['nginx:/etc/nginx']],
        ]]);

        $this->assertSame([8080, 80], $scan['all']);
        $this->assertSame(8080, $scan['primary']);
    }

    /** The port is read from the account's compose file, where every service carries the hosting layer's /proc binds. */
    public function test_the_built_service_still_leads_once_the_hosting_layer_has_added_its_mounts(): void
    {
        $scan = ComposePortScan::ofParsed(ComposeHarden::withProcMounts(['services' => [
            'nginx-ui' => ['build' => '.', 'image' => 'nginx-ui:latest', 'ports' => ['8080:8080'], 'volumes' => ['nginx:/etc/nginx']],
            'nginx' => ['image' => 'nginx:1.18.0-alpine', 'ports' => ['80:80'], 'volumes' => ['nginx:/etc/nginx']],
        ]], LxcfsProc::FILES));

        $this->assertSame(8080, $scan['primary']);
    }

    public function test_a_configured_nginx_still_leads(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => [
            'app' => ['build' => '.', 'ports' => ['8080:8080']],
            'nginx' => ['image' => 'nginx', 'ports' => ['80:80'], 'volumes' => ['./nginx.conf:/etc/nginx/conf.d/default.conf']],
        ]]);

        $this->assertSame(80, $scan['primary']);
    }

    public function test_a_stock_nginx_beside_only_images_keeps_its_rank(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => [
            'app' => ['image' => 'acme/app', 'ports' => ['8080:8080']],
            'nginx' => ['image' => 'nginx', 'ports' => ['80:80']],
        ]]);

        $this->assertSame(80, $scan['primary']);
    }

    public function test_a_build_behind_a_profile_does_not_demote_the_stock_nginx(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => [
            'dev' => ['build' => '.', 'ports' => ['8080:8080'], 'profiles' => ['dev']],
            'nginx' => ['image' => 'nginx', 'ports' => ['80:80']],
        ]]);

        $this->assertSame(80, $scan['primary']);
    }

    public function test_a_stock_nginx_alone_is_still_the_site(): void
    {
        $scan = ComposePortScan::ofParsed(['services' => [
            'worker' => ['build' => '.'],
            'nginx' => ['image' => 'nginx', 'ports' => ['80:80']],
        ]]);

        $this->assertSame(80, $scan['primary']);
    }
}
