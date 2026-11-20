<?php

namespace Tests\Integration\Deploy\Dind;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\ShellStepTagFixtures;

class ShellStepTagTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ShellStepTagFixtures;

    public function test_a_dind_exec_step_carries_the_tag_in_its_environment(): void
    {
        $cmd = $this->container()->exec(['docker', 'compose', 'up'], environment: ['DOCKER_CONFIG' => '/home/acme/.panelalpha-docker']);

        $this->assertSame(
            [
                'pa-root', 'account-container.exec', '--', 'acme', '', '',
                '2', 'PANELALPHA_STEP', 'abc', 'DOCKER_CONFIG', '/home/acme/.panelalpha-docker',
                'docker', 'compose', 'up',
            ],
            LocalRoot::plain($this->shell()->tagStep($cmd, 'abc'))
        );
    }
}
