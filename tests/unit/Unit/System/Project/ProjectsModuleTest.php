<?php

namespace Tests\Unit\System\Project;

use App\System;
use App\System\Projects;
use PHPUnit\Framework\TestCase;

class ProjectsModuleTest extends TestCase
{
    public function test_projects_accessor_returns_collection_module(): void
    {
        $system = new System();

        $this->assertInstanceOf(Projects::class, $system->projects());
    }

    public function test_projects_module_exposes_clone_copy_and_push_to_app_without_intermediates(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5) . '/core/app/System/Projects.php');
        $this->assertStringContainsString('function clone(', $source);
        $this->assertStringContainsString('function copy(', $source);
        $this->assertStringContainsString('function pushToApp(', $source);
        $this->assertStringNotContainsString('ProjectClone', $source);
        $this->assertStringNotContainsString('ProjectPush', $source);
        $this->assertStringNotContainsString('CopyHosting', $source);
        $this->assertStringNotContainsString('MutationLock', $source);
        $this->assertStringContainsString('findByUsernameOrFail', $source);
        $this->assertStringContainsString('project($', $source);
    }
}
