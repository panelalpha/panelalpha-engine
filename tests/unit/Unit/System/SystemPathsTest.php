<?php

namespace Tests\Unit\System;

use App\System;
use PHPUnit\Framework\TestCase;

class SystemPathsTest extends TestCase
{
    public function test_host_layout_accessors_use_project_names(): void
    {
        $system = new System();

        $this->assertSame('/home', $system->homesDirPath());
        $this->assertSame('/opt/panelalpha/shared-hosting', $system->engineDirPath());
        $this->assertSame('/opt/panelalpha/shared-hosting/users', $system->projectsDirPath());
        $this->assertSame(
            '/opt/panelalpha/shared-hosting/docker-compose.yml',
            $system->composeFilePath()
        );
        $this->assertSame('/home/alice', $system->projectHomeDirPath('alice'));
        $this->assertSame(
            '/opt/panelalpha/shared-hosting/users/alice',
            $system->projectDirPath('alice')
        );
        $this->assertSame(
            '/opt/panelalpha/shared-hosting/templates',
            $system->templatesDirPath()
        );
        $this->assertSame(
            '/opt/panelalpha/shared-hosting/templates/user/default/home',
            $system->projectHomeTemplateDirPath()
        );
        $this->assertSame(
            '/opt/panelalpha/shared-hosting/templates/user/dind/project',
            $system->projectFilesTemplateDirPath('dind')
        );
        $domainTemplate = $system->projectDomainTemplateDirPath();
        $this->assertContains($domainTemplate, [
            '/opt/panelalpha/shared-hosting/templates/user/default/domain',
            '/opt/panelalpha/shared-hosting/templates/user-home',
        ]);
    }
}
