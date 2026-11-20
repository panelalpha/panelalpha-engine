<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Host\HostMemory;
use App\System\Project\Dind\HostCompile;
use PHPUnit\Framework\TestCase;

/** The deploy log's line on the host build container's memory names the lever that set it. */
class HostCompileBuildMemoryLineTest extends TestCase
{
    public function test_the_default_on_a_server_whose_ram_could_not_be_read_says_so(): void
    {
        $this->assertSame(
            'Host build container memory: 4096 MB, the default, since the server\'s RAM could not be read; DEPLOY_BUILD_MEMORY replaces it',
            HostCompile::buildMemoryLine(4096, new BuildMemory('4096m', BuildMemory::HOST)),
        );
    }

    public function test_a_setting_above_the_ceiling_says_what_held_it(): void
    {
        $this->assertSame(
            'Host build container memory: 8000 MB, DEPLOY_BUILD_MEMORY held to at most half the server\'s 16000 MB'
                .' and never more than its RAM less 1500 MB for the engine (DEPLOY_ENGINE_MEMORY)',
            HostCompile::buildMemoryLine(8000, new BuildMemory('8000m', BuildMemory::SETTING_CAPPED, new HostMemory(16000, 1500))),
        );
    }

    public function test_a_setting_within_the_ceiling_is_the_operators_own(): void
    {
        $this->assertSame(
            'Host build container memory: 2048 MB, set for the server by DEPLOY_BUILD_MEMORY; the project\'s memory limit does not apply to it',
            HostCompile::buildMemoryLine(2048, new BuildMemory('2048m')),
        );
    }
}
