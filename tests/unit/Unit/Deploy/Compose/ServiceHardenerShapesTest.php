<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceHardener;
use PHPUnit\Framework\TestCase;

/** Values the hardener reads and cannot use, and services the removed secrets never touched. */
class ServiceHardenerShapesTest extends TestCase
{
    public function test_a_cpu_count_that_is_not_a_positive_number_sizes_no_thread_pool(): void
    {
        foreach (['0', '-1', 'two', '${CPUS}'] as $cpus) {
            $service = ServiceHardener::harden('app', ['image' => 'acme/app', 'cpus' => $cpus, 'environment' => ['NODE_ENV' => 'production']]);

            $this->assertArrayNotHasKey('OMP_NUM_THREADS', $service['environment'], $cpus);
            $this->assertSame('production', $service['environment']['NODE_ENV'], $cpus);
        }
    }

    public function test_a_host_network_service_whose_environment_names_no_port_publishes_nothing(): void
    {
        $service = ServiceHardener::harden('web', [
            'image' => 'acme/web',
            'network_mode' => 'host',
            'environment' => ['NODE_ENV=production', 'HOST' => 'localhost'],
        ]);

        $this->assertArrayNotHasKey('network_mode', $service);
        $this->assertArrayNotHasKey('ports', $service);
    }

    public function test_a_service_that_does_not_use_a_removed_secret_is_left_as_written(): void
    {
        [$compose, $removed] = ServiceHardener::withoutUnsafeFileSources([
            'services' => [
                'app' => ['image' => 'acme/app', 'secrets' => ['pw', 'token']],
                'worker' => ['image' => 'acme/app', 'secrets' => ['token']],
            ],
            'secrets' => ['pw' => ['file' => '/etc/shadow'], 'token' => ['file' => './token']],
        ]);

        $this->assertSame(['token'], $compose['services']['app']['secrets']);
        $this->assertSame(['image' => 'acme/app', 'secrets' => ['token']], $compose['services']['worker']);
        $this->assertSame(['secret pw: file /etc/shadow'], $removed);
    }
}
