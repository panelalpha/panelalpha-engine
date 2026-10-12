<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\NginxProxy;
use PHPUnit\Framework\TestCase;

/**
 * Every NginxProxy vhost operation works on the one directory the nginx-proxy
 * container mounts, whatever slug the current-webserver lookup returns.
 */
class NginxProxyVhostsDirTest extends TestCase
{
    private string $root;

    /** @var \ArrayObject<int, mixed> */
    private \ArrayObject $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-nginx-proxy-dir-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/webserver-config/nginx-proxy/vhosts', 0777, true);
        $this->commands = new \ArrayObject();
        $this->setCurrentWebserver('unknown');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        @unlink($this->vhostsDir() . '/gone.test.conf');
        @rmdir($this->vhostsDir());
        @rmdir($this->root . '/webserver-config/nginx-proxy');
        @rmdir($this->root . '/webserver-config');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_listing_pruning_and_deleting_share_the_nginx_proxy_directory(): void
    {
        file_put_contents($this->vhostsDir() . '/gone.test.conf', "server {}\n");
        $proxy = new NginxProxy($this->system());

        $this->assertSame($this->vhostsDir(), $proxy->domainsConfigsDirPath());
        $this->assertSame(['gone.test'], $proxy->listDomains());
        $this->assertTrue($proxy->domainConfigExists('gone.test'));
        $this->assertSame(['gone.test'], $proxy->pruneDomainConfigs([]));
        $this->assertSame([['sudo', 'rm', '-f', $this->vhostsDir() . '/gone.test.conf']], $this->commands->getArrayCopy());
    }

    private function vhostsDir(): string
    {
        return $this->root . '/webserver-config/nginx-proxy/vhosts';
    }

    private function setCurrentWebserver(?string $slug): void
    {
        $property = new \ReflectionProperty(Webserver::class, 'currentWebserver');
        $property->setValue(null, $slug);
    }

    private function system(): System
    {
        return new class ($this->root, $this->commands) extends System {
            /** @param \ArrayObject<int, mixed> $commands */
            public function __construct(private string $root, private \ArrayObject $commands)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;

                return '';
            }
        };
    }
}
