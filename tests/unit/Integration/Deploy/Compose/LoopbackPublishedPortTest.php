<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceHardener;
use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * Publishing `8080:8080` on loopback only is a security default on a shared
 * machine. Inside an account container it only means the app cannot be
 * reached, so hardening publishes it properly -- which is also what makes the
 * port visible to detection at all.
 */
final class LoopbackPublishedPortTest extends TestCase
{
    /**
     * The point of the rewrite: Gokapi published only to loopback, so the scan
     * found nothing and detection fell back to a platform default port that
     * nothing was listening on.
     */
    public function testTheRewrittenPortIsVisibleToTheScan(): void
    {
        $dir = sys_get_temp_dir() . '/pa-loopback-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $compose = $dir . '/docker-compose.yml';

        try {
            file_put_contents($compose, "services:\n  gokapi:\n    image: f0rc3/gokapi:latest\n"
                . "    ports:\n      - '" . TestNetwork::LOOPBACK . ":53842:53842'\n");
            // 8080 is the platform fallback: the scan found no published port
            // at all, which is exactly how Gokapi ended up proxied to a port
            // nothing was listening on.
            $this->assertSame(8080, ComposePortScan::primaryOf($compose), 'as the repo ships it');

            $hardened = ServiceHardener::harden('gokapi', [
                'image' => 'f0rc3/gokapi:latest',
                'ports' => [TestNetwork::LOOPBACK . ':53842:53842'],
            ]);
            file_put_contents(
                $compose,
                "services:\n  gokapi:\n    image: f0rc3/gokapi:latest\n"
                . "    ports:\n      - '" . $hardened['ports'][0] . "'\n"
            );

            $this->assertSame(53842, ComposePortScan::primaryOf($compose), 'after hardening');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
