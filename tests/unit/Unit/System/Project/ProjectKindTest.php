<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind;
use App\System\Project\PhpHosting;
use PHPUnit\Framework\TestCase;

class ProjectKindTest extends TestCase
{
    public function test_git_project_resolves_to_dind(): void
    {
        $this->assertSame('dind', $this->kindOf(['git_repo' => 'https://example.com/repo.git']));
    }

    public function test_dind_template_resolves_to_dind(): void
    {
        $this->assertSame('dind', $this->kindOf(['template' => 'dind']));
    }

    public function test_missing_git_and_template_resolves_to_php_hosting(): void
    {
        $this->assertSame('php-hosting', $this->kindOf([]));
    }

    public function test_classic_template_resolves_to_php_hosting_not_a_webserver_type(): void
    {
        $this->assertSame('php-hosting', $this->kindOf(['template' => 'default']));
    }

    /** @param array<string, mixed> $details */
    private function kindOf(array $details): string
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails($details);

        return (new System())->project($model)->kind();
    }

    public function test_system_project_factory_returns_aggregate_for_each_kind(): void
    {
        $system = new System();

        $dindModel = new ModelsUser();
        $dindModel->username = 'alice';
        $dindModel->setDetails(['git_repo' => 'https://example.com/repo.git']);

        $phpModel = new ModelsUser();
        $phpModel->username = 'bob';
        $phpModel->setDetails(['template' => 'default']);

        $dindProject = $system->project($dindModel);
        $phpProject = $system->project($phpModel);

        $this->assertInstanceOf(\App\System\Project::class, $dindProject);
        $this->assertInstanceOf(\App\System\Project::class, $phpProject);
        $this->assertNotInstanceOf(Dind::class, $dindProject);
        $this->assertNotInstanceOf(PhpHosting::class, $phpProject);
    }
}
