<?php

namespace Tests\Unit\System;

use App\Lib\Lighthouse\LighthouseTarget;
use App\System\ComposeProject;
use App\System\EnginePaths;
use App\System\Project\Dind\TenantEgressGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ComposeProjectTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** Compose names the project after the engine directory unless the stack names it. */
    public function test_the_name_is_the_one_compose_derives(): void
    {
        $compose = Yaml::parseFile($this->root() . '/docker-compose.yml');
        $this->assertArrayNotHasKey('name', $compose, 'a project name set in the compose file must be set in ComposeProject too');
        $this->assertStringNotContainsString(
            'COMPOSE_PROJECT_NAME',
            (string) file_get_contents($this->root() . '/.env.example'),
            'a project name set in .env must be set in ComposeProject too'
        );

        $this->assertSame(basename(EnginePaths::ENGINE_DIR), ComposeProject::NAME);
    }

    public function test_container_names_follow_the_project_name(): void
    {
        $this->assertSame(ComposeProject::NAME . '-sites-db-1', ComposeProject::container('sites-db'));
        $this->assertContains(ComposeProject::container('sites-db'), TenantEgressGuard::DATABASE_NAMES);
        $this->assertTrue(LighthouseTarget::blocks(ComposeProject::container('core')));
    }
}
