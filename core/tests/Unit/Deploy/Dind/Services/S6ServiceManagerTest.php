<?php

namespace Tests\Unit\Deploy\Dind\Services;

use App\System\Project\Dind\Services\S6ServiceManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class S6ServiceManagerTest extends TestCase
{
    public function test_signals_map_to_s6_svc_flags(): void
    {
        $manager = (new \ReflectionClass(S6ServiceManager::class))->newInstanceWithoutConstructor();

        $this->assertSame(['s6-svc', '-h', '/run/service/docker'], $manager->signalArgv('docker', 'HUP'));
        $this->assertSame(['s6-svc', '-t', '/run/service/cron'], $manager->signalArgv('cron', 'term'));
        $this->assertSame(['s6-svc', '-u', '/run/service/docker'], $manager->startArgv('docker'));
    }

    public function test_a_signal_s6_cannot_send_is_refused(): void
    {
        $manager = (new \ReflectionClass(S6ServiceManager::class))->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $manager->signalArgv('docker', 'WINCH');
    }

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

    /** Names end up in paths and shell text unquoted. */
    public function test_a_name_that_is_not_a_plain_word_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        S6ServiceManager::applyArgv('x; rm -rf /');
    }

    private function assertValidSh(string $script): void
    {
        exec('sh -n -c ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
