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

    /** Names end up in paths and shell text unquoted. */
    public function test_a_name_that_is_not_a_plain_word_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        S6ServiceManager::applyArgv('x; rm -rf /');
    }

    public function test_a_name_with_a_trailing_newline_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        S6ServiceManager::applyArgv("app\n");
    }
}
