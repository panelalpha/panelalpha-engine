<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeHarden;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use App\System\Project\Dind\LxcfsProc;
use PHPUnit\Framework\TestCase;

/** Files with no service map, entries that are not services, and mounts that name no target. */
class ComposeHardenShapesTest extends TestCase
{
    public function test_a_file_without_services_still_loses_its_unsafe_secrets(): void
    {
        $result = ComposeHarden::applyReporting(['secrets' => ['s' => ['file' => '/var/run/docker.sock'], 'ok' => ['file' => './pw.txt']]]);

        $this->assertSame(['secrets' => ['ok' => ['file' => './pw.txt']]], $result['compose']);
        $this->assertSame(['secret s: file /var/run/docker.sock'], $result['removed']);
    }

    public function test_proc_mounts_skip_entries_that_are_not_services_and_keep_odd_volumes(): void
    {
        $compose = ComposeHarden::withProcMounts(['services' => [
            'anchor' => 'not-a-service',
            'app' => ['image' => 'acme/app', 'volumes' => [42, '/proc/meminfo']],
        ]], ['meminfo', 'cpuinfo']);

        $this->assertSame('not-a-service', $compose['services']['anchor']);
        $this->assertSame([
            42,
            '/proc/meminfo',
            ['type' => 'bind', 'source' => LxcfsProc::PROC_DIR . '/cpuinfo', 'target' => '/proc/cpuinfo'],
        ], $compose['services']['app']['volumes']);
    }

    public function test_the_backing_service_question_is_the_sidecar_engines(): void
    {
        $db = ['image' => 'postgres:16'];
        $web = ['build' => '.', 'ports' => ['3000:3000']];

        $this->assertSame(SidecarEngine::isBackingService('db', $db), ComposeHarden::isBackingService('db', $db));
        $this->assertTrue(ComposeHarden::isBackingService('db', $db));
        $this->assertFalse(ComposeHarden::isBackingService('web', $web));
    }
}
