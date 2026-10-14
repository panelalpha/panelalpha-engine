<?php

namespace Tests\Unit\Console;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `pae project:deploy:list <project>` and `deploy:log:prune` read the same history as the API. */
class DeployHistoryCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->user = $this->makeUser('dhc' . bin2hex(random_bytes(4)), ['template' => 'dind']);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->user->username);
        parent::tearDown();
    }

    private function deploy(string $start): string
    {
        $logger = DeployLogger::start($this->user->username);
        $logger->info($start);
        $logger->finish(DeployLogger::STATUS_SUCCESS);

        return $logger->getDeployId();
    }

    public function test_the_list_shows_each_deploys_status_kind_source_branch_and_commit(): void
    {
        $id = $this->deploy('Deploy started (source: git, commit: a8c1f4d9e2b7305c6d4a1e8f9b0c2d3e4f5a6b7c, branch: main)');

        $exit = Artisan::call('project:deploy:list', ['project' => $this->user->username]);
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/' . preg_quote($id, '/') . '\s*\|\s*success\s*\|\s*redeploy\s*\|\s*git\s*\|\s*main\s*\|\s*-\s*\|\s*a8c1f4d9e2b7\s*\|/', $out);
    }

    public function test_an_unknown_project_is_a_plain_not_found(): void
    {
        $exit = Artisan::call('project:deploy:list', ['project' => 'nobody']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Project 'nobody' not found.", Artisan::output());
    }

    public function test_prune_keeps_deploy_log_keep_logs_unless_told_otherwise(): void
    {
        $this->deploy('Deploy started (source: rebuild)');
        sleep(1);
        $this->deploy('Deploy started (source: rebuild)');
        sleep(1);
        $newest = $this->deploy('Deploy started (source: rebuild)');
        config(['deploy.log_keep' => 1]);

        Artisan::call('deploy:log:prune', ['--project' => $this->user->username]);

        $this->assertSame([$newest], array_column(DeployLogger::listDeploys($this->user->username), 'id'));
    }
}
