<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\ProxyRule;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The engine calls a hosting account a project everywhere an operator reads it:
 * no command prints "User" for the project it works on.
 */
class ProjectWordingTest extends TestCase
{
    use InMemoryDatabase;

    private string $logged;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = 'wd' . bin2hex(random_bytes(4));
        $this->bootInMemoryDatabase();
        $this->makeUser('alice', ['template' => 'default'], 'alice.example.test');
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->logged);
        DeployLogger::deleteUserLogs('alice');
        parent::tearDown();
    }

    public function test_push_names_the_project_it_does_not_find(): void
    {
        $exit = Artisan::call('project:push', ['project' => 'nosuch', 'target' => 'alice']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No such project: 'nosuch'", Artisan::output());
    }

    public function test_staging_names_the_project_it_does_not_find(): void
    {
        $exit = Artisan::call('project:staging', ['project' => 'nosuch']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No such project: 'nosuch'", Artisan::output());
    }

    public function test_the_app_check_names_the_project_it_does_not_find(): void
    {
        $exit = Artisan::call('project:deploy:check', ['project' => 'nosuch']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No such project: 'nosuch'", Artisan::output());
    }

    public function test_attach_names_the_project_it_does_not_find(): void
    {
        $exit = Artisan::call('project:attach', ['project' => 'nosuch']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Project `nosuch` not found in database.', Artisan::output());
    }

    public function test_the_limits_are_headed_with_the_project(): void
    {
        $this->assertSame(0, Artisan::call('project:limit:get', ['--project' => 'alice']));

        $this->assertStringContainsString('Project `alice`:', Artisan::output());
    }

    public function test_the_dind_recovery_says_project(): void
    {
        $exit = Artisan::call('project:dind:recover', ['--project' => 'alice']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Project `alice` is not a DinD (sysbox) project.', Artisan::output());
    }

    public function test_a_deploy_log_is_headed_with_the_project(): void
    {
        $logger = DeployLogger::start($this->logged);
        $logger->info('first line');

        $this->artisan('project:deploy:log', ['project' => $this->logged])
            ->expectsOutputToContain("Project: {$this->logged}")
            ->doesntExpectOutputToContain('User:')
            ->assertExitCode(0);
    }

    public function test_the_deploy_list_column_is_a_project(): void
    {
        $logger = DeployLogger::start($this->logged);
        $logger->finish(DeployLogger::STATUS_SUCCESS);

        $this->artisan('project:deploy:list')
            ->expectsOutputToContain('Project')
            ->doesntExpectOutputToContain('Username')
            ->assertExitCode(0);
    }

    public function test_the_proxy_rule_list_column_is_a_project(): void
    {
        ProxyRule::query()->create([
            'owner_scope' => 'user',
            'username' => 'alice',
            'transport' => 'http',
            'listen_port' => 443,
            'server_name' => 'alice.example.test',
            'upstream_host' => 'alice',
            'upstream_port' => 8080,
        ]);

        $this->artisan('proxy:rule:list')
            ->expectsOutputToContain('Project')
            ->doesntExpectOutputToContain('User')
            ->assertExitCode(0);
    }
}
