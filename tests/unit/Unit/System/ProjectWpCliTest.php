<?php

namespace Tests\Unit\System;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use PHPUnit\Framework\TestCase;

/**
 * A static/no-PHP DinD project's runtime is Dind, not PhpHosting, so
 * runWpCli() throws -- a legitimate "not supported here" condition, not a
 * crash. WpCliController::run() had no try/catch around this call, so it
 * surfaced as a raw 500 instead of a 422 with this message.
 */
class ProjectWpCliTest extends TestCase
{
    public function test_a_dind_project_without_php_hosting_rejects_wp_cli(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind']);

        $project = new Project(new System(), $model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP-CLI runs only on traditional PHP hosting projects.');

        $project->runWpCli(['--version']);
    }
}
