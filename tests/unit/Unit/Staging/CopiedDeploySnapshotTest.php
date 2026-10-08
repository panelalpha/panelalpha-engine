<?php

namespace Tests\Unit\Staging;

use App\Models\User;
use Tests\TestCase;

class CopiedDeploySnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_copied_snapshot_keeps_frozen_strategy_and_drops_unrelated_keys(): void
    {
        $snapshot = User::copiedDeploySnapshot([
            'deploy_strategy' => 'static',
            'deploy_label' => 'HTML',
            'deploy_runtime' => 'nginx',
            'deploy_source' => 'git',
            'deploy_image' => null,
            'git_commit' => 'abc123',
            'git_branch' => 'main',
            'disk_space_limit' => 100,
            'git_token' => 'secret',
            'env_vars' => ['APP_KEY' => 'nope'],
        ]);

        $this->assertSame('static', $snapshot['deploy_strategy']);
        $this->assertSame('HTML', $snapshot['deploy_label']);
        $this->assertSame('nginx', $snapshot['deploy_runtime']);
        $this->assertSame('git', $snapshot['deploy_source']);
        $this->assertNull($snapshot['deploy_image']);
        $this->assertSame('abc123', $snapshot['git_commit']);
        $this->assertSame('main', $snapshot['git_branch']);
        $this->assertArrayNotHasKey('disk_space_limit', $snapshot);
        $this->assertArrayNotHasKey('git_token', $snapshot);
        $this->assertArrayNotHasKey('env_vars', $snapshot);
    }

    public function test_details_for_copied_project_include_deploy_strategy(): void
    {
        $live = new User();
        $live->details = [
            'template' => 'dind',
            'deploy_strategy' => 'static',
            'deploy_label' => 'HTML',
            'git_repo' => 'https://github.com/example/site.git',
            'git_branch' => 'main',
            'app_port' => 8080,
            'disk_space_limit' => 50,
            'git_token' => 'secret',
        ];

        $details = $live->detailsForCopiedProject('stgapp', [
            'async_status' => ['staging' => 'running'],
        ]);

        $this->assertSame('/home/stgapp', $details['home_dir']);
        $this->assertSame('stgapp_', $details['mysql_prefix']);
        $this->assertSame('dind', $details['template']);
        $this->assertSame('static', $details['deploy_strategy']);
        $this->assertSame('HTML', $details['deploy_label']);
        $this->assertSame('https://github.com/example/site.git', $details['git_repo']);
        $this->assertSame('main', $details['git_branch']);
        $this->assertSame(8080, $details['app_port']);
        $this->assertSame(50, $details['disk_space_limit']);
        $this->assertFalse($details['dedicated_ipv4']);
        $this->assertSame(['staging' => 'running'], $details['async_status']);
        $this->assertArrayNotHasKey('git_token', $details);
    }

    public function test_apply_deploy_snapshot_writes_strategy_onto_dest(): void
    {
        $live = new User();
        $live->details = [
            'deploy_strategy' => 'static',
            'deploy_runtime' => 'nginx',
        ];

        $dest = new User();
        $dest->details = [
            'template' => 'dind',
            'home_dir' => '/home/stgapp',
        ];
        $dest->applyDeploySnapshotFrom($live);

        $this->assertSame('static', $dest->getDeployStrategy());
        $this->assertSame('nginx', $dest->getDeployRuntime());
        $this->assertSame('dind', $dest->getDetails()['template']);
    }

    public function test_clone_and_staging_builders_use_copied_project_details(): void
    {
        $userModel = file_get_contents(app_path('Models/User.php'));
        $this->assertIsString($userModel);
        $this->assertStringContainsString('function detailsForCopiedProject(', $userModel);
        $this->assertStringContainsString('function applyDeploySnapshotFrom(', $userModel);

        $stagingFn = strpos($userModel, 'function makePendingStaging(');
        $this->assertNotFalse($stagingFn);
        $nextFn = strpos($userModel, 'function findByUsernameOrFail(', $stagingFn);
        $this->assertNotFalse($nextFn);
        $stagingBlock = substr($userModel, $stagingFn, $nextFn - $stagingFn);
        $this->assertStringContainsString('detailsForCopiedProject($destUsername', $stagingBlock);

        $clone = file_get_contents(app_path('Http/Controllers/UserController.php'));
        $this->assertIsString($clone);
        $clonePos = strpos($clone, 'function clone(string $username, UserCloneRequest $request)');
        $this->assertNotFalse($clonePos);
        $verifyPos = strpos($clone, 'function verifyNewUsername(', $clonePos);
        $this->assertNotFalse($verifyPos);
        $cloneBlock = substr($clone, $clonePos, $verifyPos - $clonePos);
        $this->assertStringContainsString('detailsForCopiedProject($newUsername)', $cloneBlock);
        $this->assertStringNotContainsString("'deploy_strategy'", $cloneBlock);
    }
}
