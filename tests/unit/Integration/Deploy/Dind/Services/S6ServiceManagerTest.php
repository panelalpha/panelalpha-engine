<?php

namespace Tests\Integration\Deploy\Dind\Services;

use App\System\Project\Dind\Services\S6ServiceManager;
use PHPUnit\Framework\TestCase;

class S6ServiceManagerTest extends TestCase
{
    /** Stopping waits for the service, then kills it, the way supervisord's stopwaitsecs did. */
    public function test_stop_waits_then_kills(): void
    {
        $manager = (new \ReflectionClass(S6ServiceManager::class))->newInstanceWithoutConstructor();
        $script = $manager->stopArgv('docker')[2];

        $this->assertStringContainsString('s6-svc -wd -T 30000 -d /run/service/docker', $script);
        $this->assertStringContainsString('s6-svc -k /run/service/docker', $script);
        $this->assertValidSh($script);
    }

    public function test_apply_copies_the_service_and_restarts_it_unless_down(): void
    {
        $script = S6ServiceManager::applyArgv('cloudflared')[2];

        $this->assertStringContainsString('cp /etc/s6/account/cloudflared/run /run/service/cloudflared/run', $script);
        $this->assertStringContainsString('cp -a /etc/s6/account/cloudflared/env /run/service/cloudflared/env', $script);
        $this->assertStringContainsString('s6-svscanctl -a /run/service', $script);
        $this->assertStringContainsString('s6-svc -u /run/service/cloudflared', $script);
        $this->assertValidSh($script);
    }

    private function assertValidSh(string $script): void
    {
        exec('sh -n -c ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
