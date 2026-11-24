<?php

namespace Tests\Integration\Project;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Project\ProjectDeployState;
use App\Lib\Project\ProjectOverview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectOverviewTest extends TestCase
{
    private const NOW = '2026-11-24 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_type_is_normalized_from_the_deploy_strategy(): void
    {
        $cases = [
            'laravel' => 'laravel',
            'php' => 'php',
            'nextjs' => 'node',
            'express' => 'node',
            'django' => 'python',
            'rails' => 'ruby',
            'go' => 'go',
            'dotnet' => 'dotnet',
            'compose' => 'docker',
            'dockerfile' => 'docker',
            'static' => 'static',
            'railpack' => 'other',
            'fallback' => 'other',
        ];
        foreach ($cases as $strategy => $type) {
            $this->assertSame($type, ProjectOverview::type(['deploy_strategy' => $strategy]), $strategy);
        }

        $this->assertNull(ProjectOverview::type([]));
    }

    public function test_wordpress_is_named_whatever_strategy_deployed_it(): void
    {
        $this->assertSame('wordpress', ProjectOverview::type(['deploy_strategy' => 'compose', 'deploy_label' => 'WordPress']));
        $this->assertSame('wordpress', ProjectOverview::type(['deploy_strategy' => 'paemd'], 'https://github.com/WordPress/WordPress.git'));
    }

    public function test_status_prefers_suspended_then_failed_then_container_state(): void
    {
        $this->sampleRun('-1 minute', true);
        $this->sampled('up-app', '-1 minute');

        $this->assertSame('suspended', $this->statusOf('down-app', 'suspended', ['deploy_strategy' => 'php']));
        $this->assertSame('none', $this->statusOf('fresh-app', 'active', []));
        $this->assertSame('failed', $this->statusOf('up-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'failed']));
        $this->assertSame('live', $this->statusOf('up-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
        $this->assertSame('down', $this->statusOf('down-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
    }

    public function test_no_recent_sample_that_saw_every_container_never_reports_down(): void
    {
        $this->sampleRun('-10 minutes', true);
        $this->sampleRun('-1 minute', false);

        $this->assertSame('live', $this->statusOf('down-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
    }

    public function test_a_project_seen_by_a_later_partial_sample_is_still_running(): void
    {
        $this->sampleRun('-2 minutes', true);
        $this->sampleRun('-1 minute', false);
        $this->sampled('up-app', '-1 minute');

        $this->assertSame('live', $this->statusOf('up-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
    }

    public function test_a_deploy_finished_after_the_sample_is_not_down_yet(): void
    {
        $this->sampleRun('-1 minute', true);
        ProjectDeployState::record('new-app', ['id' => 'd1', 'status' => 'success', 'finished_at' => Carbon::now()->subSeconds(20)->getTimestamp()]);

        $this->assertSame('live', $this->statusOf('new-app', 'active', ['deploy_strategy' => 'php']));
    }

    public function test_the_stored_deploy_decides_building_and_failed(): void
    {
        $this->sampleRun('-1 minute', true);
        $this->sampled('up-app', '-1 minute');
        $details = ['deploy_strategy' => 'php', 'deployment_status' => 'success'];

        ProjectDeployState::record('up-app', ['id' => 'd2', 'status' => 'running', 'finished_at' => null]);
        $this->assertSame('building', $this->statusOf('up-app', 'active', $details));

        ProjectDeployState::record('up-app', ['id' => 'd2', 'status' => 'failed', 'finished_at' => Carbon::now()->subMinutes(5)->getTimestamp()]);
        $this->assertSame('failed', $this->statusOf('up-app', 'active', $details));

        ProjectDeployState::record('up-app', ['id' => '', 'status' => 'failed']);
        $this->assertSame('live', $this->statusOf('up-app', 'active', $details), 'a pointer with no deploy has no verdict');
    }

    public function test_admin_sso_is_read_from_the_capability_list_not_from_any_mention(): void
    {
        $this->assertTrue($this->shipped('https://github.com/wordpress/wordpress')->supportsAdminSso());
        $this->assertTrue($this->shipped('https://github.com/chatwoot/chatwoot')->supportsAdminSso());
        // Both scripts explain in a comment why they have no users:sso.
        $this->assertFalse($this->shipped('https://github.com/alinto/sogo')->supportsAdminSso());
        $this->assertFalse($this->shipped('https://github.com/magento/magento2')->supportsAdminSso());
    }

    public function test_admin_sso_prefers_what_the_deploy_recorded(): void
    {
        $user = new User(['username' => 'wp']);
        $user->setDetails(['git_repo' => 'https://github.com/wordpress/wordpress']);
        $this->assertTrue(ProjectOverview::adminSso($user, $user->getDetails()));

        $user->setDetails(['deploy_admin_sso' => false]);
        $this->assertFalse(ProjectOverview::adminSso($user, $user->getDetails()));

        $this->assertFalse(ProjectOverview::adminSso(new User(['username' => 'bare']), []));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function statusOf(string $username, string $status, array $details): string
    {
        $user = User::query()->firstOrNew(['username' => $username]);
        $user->forceFill(['domain' => $username . '.example.com', 'details' => $details, 'status' => $status])->save();

        return ProjectOverview::status($user, $details);
    }

    private function sampleRun(string $ago, bool $listed): void
    {
        DB::table('project_sample_runs')->insert(['timestamp' => Carbon::now('UTC')->modify($ago)->format('Y-m-d H:i:s'), 'listed' => $listed]);
    }

    private function sampled(string $username, string $ago): void
    {
        DB::table('project_metrics')->insert(['timestamp' => Carbon::now('UTC')->modify($ago)->format('Y-m-d H:i:s'), 'username' => $username, 'cpu_percent' => 1.0]);
    }

    private function shipped(string $gitUrl): AppConfig
    {
        $config = AppConfigLocator::findShipped(new LocalAppConfigSource(), $gitUrl);
        $this->assertNotNull($config, $gitUrl);

        return $config;
    }
}
