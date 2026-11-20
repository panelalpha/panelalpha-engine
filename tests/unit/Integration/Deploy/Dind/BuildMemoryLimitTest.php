<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Host\HostMemory;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildMemoryLimitFixtures;
use Tests\Support\LocalRoot;

/**
 * The host build container's memory ceiling, and Node being told about it.
 *
 * A build is a host resource -- a throwaway container on the engine, before
 * the project's own container exists -- so the operator sets it, not a
 * hosting plan. It was a constant, and a flat 2g is not enough for every
 * project: Chamilo 2.x's Encore build OOMs inside it on a 15 GB host and
 * succeeds at a 3584 MB heap, which our own 70% rule reaches in a ~5 GB
 * container. nextcloud/server's webpack pass is the same shape, because
 * TerserPlugin forks one minifier per CPU and their heaps sum past a flat
 * limit before any single one reaches its own cap.
 */
class BuildMemoryLimitTest extends TestCase
{
    use BuildMemoryLimitFixtures;

    public function test_the_builder_gets_the_host_ceiling(): void
    {
        $request = DindEngine::builderFor('', new HostMemory(3790))->nodeBuildArgv(
            new EngineAccount('demo', '/home/demo', '1001:1001'),
            'node:24-bookworm-slim',
            'npm ci',
            'npm run build'
        );
        $build = LocalRoot::build($request);

        $this->assertSame('1895m', $this->memoryFlag($build));
        $this->assertSame([], LocalRoot::plain($request));
    }
}
