<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\Runtime\Php\PhpApacheConfig;
use PHPUnit\Framework\TestCase;

/**
 * Behind the engine's proxy a PHP app saw REMOTE_ADDR=172.25.0.1, the Docker
 * bridge gateway, for every visitor (engine#11). The proxy already sends the
 * client in X-Real-IP; Apache has to be told to believe it, and only from a
 * private source so a visitor cannot pick their own address.
 */
class ApacheRealClientIpTest extends TestCase
{
    public function test_the_image_enables_mod_remoteip(): void
    {
        $this->assertContains('remoteip', PhpApacheConfig::MODULES);
        $this->assertStringContainsString('remoteip', PhpApacheConfig::modules());
    }

    public function test_the_client_address_comes_from_the_proxy_header(): void
    {
        $vhost = PhpApacheConfig::vhost(8000);

        $this->assertMatchesRegularExpression('/^RemoteIPHeader X-Real-IP$/m', $vhost);
    }

    public function test_only_private_sources_are_trusted_to_send_it(): void
    {
        $this->assertSame(1, preg_match('/^RemoteIPTrustedProxy (.+)$/m', PhpApacheConfig::vhost(8000), $m));

        $ranges = explode(' ', trim($m[1]));
        sort($ranges);
        $this->assertSame(['10.0.0.0/8', '127.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'], $ranges);
    }

    /**
     * The classic PHP stack had a remoteip.conf, but only in templates/user-config,
     * which nothing renders; accounts are built from templates/user/default/project.
     */
    public function test_the_classic_php_stack_mounts_its_remoteip_config(): void
    {
        $dir = dirname(__DIR__, 5) . '/templates/user/default/project';
        if (!is_dir($dir)) {
            $this->markTestSkipped("No account template at {$dir}.");
        }

        $conf = (string) file_get_contents("{$dir}/apache-conf/remoteip.conf");
        $this->assertMatchesRegularExpression('/^RemoteIPHeader X-Real-IP$/m', $conf);
        $this->assertMatchesRegularExpression('/^RemoteIPTrustedProxy 172\.16\.0\.0\/12$/m', $conf);

        foreach (['docker-compose.yml-fpm-apache.blade.php', 'docker-compose.yml.blade.php'] as $compose) {
            $this->assertStringContainsString(
                './apache-conf/remoteip.conf:/etc/apache2/conf-enabled/remoteip.conf',
                (string) file_get_contents("{$dir}/{$compose}"),
                $compose
            );
        }
    }

    /** Server context, so it covers the vhost and anything a recipe adds beside it. */
    public function test_it_is_set_outside_the_vhost(): void
    {
        $vhost = PhpApacheConfig::vhost(8000);

        $this->assertLessThan(strpos($vhost, '<VirtualHost'), strpos($vhost, 'RemoteIPHeader'));
    }
}
