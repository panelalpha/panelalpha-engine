<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceDependencies;
use PHPUnit\Framework\TestCase;

class ServiceDependenciesRenameKeyedTest extends TestCase
{
    public function test_a_rename_follows_the_keyed_form_and_keeps_conditions_and_aliases(): void
    {
        $service = [
            'depends_on' => ['db' => ['condition' => 'service_healthy'], 'cache' => ['condition' => 'service_started']],
            'links' => ['db:database', 'cache'],
            'network_mode' => 'service:DB',
            'volumes_from' => ['db:ro', 'container:db', 'cache', 5],
        ];

        $this->assertSame([
            'depends_on' => ['db-sidecar' => ['condition' => 'service_healthy'], 'cache' => ['condition' => 'service_started']],
            'links' => ['db-sidecar:database', 'cache'],
            'network_mode' => 'service:db-sidecar',
            'volumes_from' => ['db-sidecar:ro', 'container:db', 'cache', 5],
        ], ServiceDependencies::renamed($service, 'db', 'db-sidecar'));
    }
}
