<?php

namespace Tests\Unit\Deploy\Dind\Services;

use App\System\Project\Dind\Services\SupervisordServiceManager;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Accounts rendered before s6 keep supervisord until they are next rendered. */
class SupervisordServiceManagerTest extends TestCase
{
    public function test_the_connector_program_carries_its_environment(): void
    {
        $on = SupervisordServiceManager::programConf('cloudflared', true, ['TUNNEL_TOKEN' => 'tok"en\\x']);
        $off = SupervisordServiceManager::programConf('cloudflared', false);

        $this->assertStringContainsString("[program:cloudflared]\n", $on);
        $this->assertStringContainsString("command=cloudflared --no-autoupdate tunnel run\n", $on);
        $this->assertStringContainsString("autostart=true\n", $on);
        $this->assertStringContainsString('environment=TUNNEL_TOKEN="tok\\"en\\\\x"', $on);
        // cloudflared reads TUNNEL_TOKEN itself: the token stays out of argv.
        $this->assertStringNotContainsString('--token', $on);
        $this->assertStringContainsString("autostart=false\n", $off);
        $this->assertStringNotContainsString('environment=', $off);
    }

    public function test_only_programs_it_knows_are_written(): void
    {
        $this->expectException(LogicException::class);
        SupervisordServiceManager::programConf('nginx', true);
    }

    public function test_commands_go_through_supervisorctl(): void
    {
        $manager = (new \ReflectionClass(SupervisordServiceManager::class))->newInstanceWithoutConstructor();
        $ctl = ['supervisorctl', '-c', '/etc/supervisor/supervisord.conf'];

        $this->assertSame([...$ctl, 'signal', 'HUP', 'docker'], $manager->signalArgv('docker', 'hup'));
        $this->assertSame([...$ctl, 'stop', 'docker'], $manager->stopArgv('docker'));
        $this->assertSame([...$ctl, 'start', 'docker'], $manager->startArgv('docker'));
    }
}
