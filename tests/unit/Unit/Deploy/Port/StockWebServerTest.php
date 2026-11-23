<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\StockWebServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A web server's stock image with nothing of the project's can serve only its own default page. */
class StockWebServerTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function services(): array
    {
        return [
            'a bare nginx' => [['image' => 'nginx:1.18.0-alpine', 'ports' => ['80:80']], true],
            'nginx with its config in a named volume' => [['image' => 'nginx:1.18.0-alpine', 'volumes' => ['nginx:/etc/nginx']], true],
            'httpd with an anonymous log volume' => [['image' => 'docker.io/library/httpd:2.4', 'volumes' => ['/usr/local/apache2/logs']], true],
            'caddy with a tmpfs cache' => [['image' => 'caddy', 'volumes' => [['type' => 'tmpfs', 'target' => '/var/cache/caddy']]], true],
            'nginx with the hosting layer\'s /proc binds' => [['image' => 'nginx', 'volumes' => [['type' => 'bind', 'source' => '/var/lib/lxcfs/proc/meminfo', 'target' => '/proc/meminfo']]], true],
            'an application image' => [['image' => 'acme/app', 'ports' => ['80:80']], false],
            'nginx built from the checkout' => [['image' => 'nginx', 'build' => '.'], false],
            'nginx given a config file' => [['image' => 'nginx', 'volumes' => ['./nginx.conf:/etc/nginx/conf.d/default.conf:ro']], false],
            'nginx given a site by bind' => [['image' => 'nginx', 'volumes' => [['type' => 'bind', 'source' => './public', 'target' => '/srv']]], false],
            'nginx given a site through a variable' => [['image' => 'nginx', 'volumes' => ['${SITE:-./site}:/usr/share/nginx/html']], false],
            'nginx serving a named volume' => [['image' => 'nginx', 'volumes' => ['static:/usr/share/nginx/html']], false],
            'nginx fronting a service' => [['image' => 'nginx', 'depends_on' => ['app']], false],
            'nginx run with a command' => [['image' => 'nginx', 'command' => 'nginx -c /conf/site.conf'], false],
            'nginx given compose configs' => [['image' => 'nginx', 'configs' => [['source' => 'site', 'target' => '/etc/nginx/conf.d/site.conf']]], false],
            'a volume entry it cannot read' => [['image' => 'nginx', 'volumes' => [42]], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $service
     */
    #[DataProvider('services')]
    public function test_only_an_unconfigured_web_server_serves_only_its_default_page(array $service, bool $expected): void
    {
        $this->assertSame($expected, StockWebServer::servesOnlyItsDefaultPage($service));
    }
}
