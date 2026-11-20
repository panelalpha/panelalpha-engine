<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Images;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * Only shell-valid names with scalar values reach a host build's environment, so no key can smuggle in a flag.
 */
class DindHostBuilderCallerEnvTest extends TestCase
{
    public function test_invalid_names_and_values_are_dropped_from_the_build_environment(): void
    {
        $build = LocalRoot::build((new DindHostBuilder)->nodeBuildArgv(
            new EngineAccount('acme', '/home/acme', '1001:1002'),
            Images::NODE_IMAGE,
            'npm ci',
            'npm run build',
            ['lower' => 'x', 'BAD KEY' => 'y', '--privileged' => 'z', 'LIST' => ['a'], 'FLAG' => true, 'PORT' => 8080, 'MODE' => 'prod']
        ));

        $this->assertSame('8080', $build['environment']['PORT'] ?? null);
        $this->assertSame('prod', $build['environment']['MODE'] ?? null);
        foreach (['lower', 'BAD KEY', '--privileged', 'LIST', 'FLAG'] as $dropped) {
            $this->assertArrayNotHasKey($dropped, $build['environment']);
        }
    }
}
