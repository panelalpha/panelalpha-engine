<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Services\ServiceManager;
use App\System\Project\Dind\TenantEgressGuard;
use Tests\TestCase;

/**
 * The guard's service is turned on exactly when its script is
 * written, through whichever init the account runs.
 */
class TenantEgressGuardServiceTest extends TestCase
{
    /** @return array<string, string> the files written into entrypoint.d */
    private function setUpScripts(bool $wantService): array
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1234];

        $written = [];
        $filesystem = $this->createStub(System\Filesystem::class);
        $filesystem->method('writeFileReplacingPath')->willReturnCallback(
            function (string $path, string $contents) use (&$written): void {
                $written[basename($path)] = $contents;
            }
        );
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);

        $services = $this->createMock(ServiceManager::class);
        $services->expects($this->once())->method('configure')->with(TenantEgressGuard::SERVICE, $wantService);

        $dind = $this->getMockBuilder(Dind::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['system', 'projectDirPath', 'userModel', 'services'])
            ->getMock();
        $dind->method('system')->willReturn($system);
        $dind->method('projectDirPath')->willReturn('/home/acme/project');
        $dind->method('userModel')->willReturn($model);
        $dind->expects($this->once())->method('services')->willReturn($services);

        $dind->setupEntrypointInitScripts();

        return $written;
    }

    public function test_on_by_default_beside_its_script(): void
    {
        config(['env.DIND_EGRESS_GUARD' => null]);

        $this->assertArrayHasKey(TenantEgressGuard::FILE, $this->setUpScripts(true));
    }

    public function test_switched_off_with_its_script(): void
    {
        config(['env.DIND_EGRESS_GUARD' => 'false']);

        $written = $this->setUpScripts(false);
        $this->assertArrayNotHasKey(TenantEgressGuard::FILE, $written);
        $this->assertArrayHasKey('useradd.sh', $written);
    }
}
