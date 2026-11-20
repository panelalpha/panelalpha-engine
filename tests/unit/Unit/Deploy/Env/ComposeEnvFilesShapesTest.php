<?php

namespace Tests\Unit\Deploy\Env;

use App\Lib\Deploy\Env\ComposeEnvFiles;
use PHPUnit\Framework\TestCase;

/**
 * Compose files whose `services` is missing or holds an entry that is no mapping: read and rewritten without touching them.
 */
class ComposeEnvFilesShapesTest extends TestCase
{
    public function test_a_file_without_services_is_returned_as_it_is(): void
    {
        $compose = ['version' => '3', 'volumes' => ['data' => null]];

        $this->assertSame([$compose, []], ComposeEnvFiles::attach($compose, '.panelalpha.env'));
        $this->assertSame([$compose, false], ComposeEnvFiles::detach($compose, '.panelalpha.env'));
    }

    public function test_a_service_that_is_no_mapping_is_left_alone(): void
    {
        $compose = ['services' => ['broken' => 'nginx', 'app' => ['image' => 'app:1', 'env_file' => '.env']]];

        [$attached, $changed] = ComposeEnvFiles::attach($compose, '.panelalpha.env');

        $this->assertSame(['app'], $changed);
        $this->assertSame('nginx', $attached['services']['broken']);
        $this->assertSame(['.env'], ComposeEnvFiles::pathsIn($compose));
    }
}
