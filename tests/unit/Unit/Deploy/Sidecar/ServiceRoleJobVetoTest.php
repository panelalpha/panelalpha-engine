<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\ServiceRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A job-named service sharing another's network or process namespace is part of that service, not a one-shot job.
 */
class ServiceRoleJobVetoTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function namespaceKeys(): array
    {
        return [
            'network_mode' => ['network_mode', 'service:web'],
            'pid' => ['pid', 'host'],
            'network' => ['network', 'backend'],
        ];
    }

    #[DataProvider('namespaceKeys')]
    public function test_a_shared_namespace_vetoes_a_job(string $key, mixed $value): void
    {
        $job = ['command' => './manage.py migrate --noinput'];

        $this->assertTrue(ServiceRole::isJobService('migrate', $job));
        $this->assertFalse(ServiceRole::isJobService('migrate', $job + [$key => $value]));
    }
}
