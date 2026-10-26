<?php

namespace Tests\Unit\Project;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Project\ProjectOverview;
use App\Models\User;
use Tests\TestCase;

class ProjectOverviewTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectOverview::fakeRunning(false);
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
        ProjectOverview::fakeRunning(['up-app']);

        $this->assertSame('suspended', $this->statusOf('down-app', 'suspended', ['deploy_strategy' => 'php']));
        $this->assertSame('none', $this->statusOf('fresh-app', 'active', []));
        $this->assertSame('failed', $this->statusOf('up-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'failed']));
        $this->assertSame('live', $this->statusOf('up-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
        $this->assertSame('down', $this->statusOf('down-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
    }

    public function test_an_unreadable_container_list_never_reports_down(): void
    {
        ProjectOverview::fakeRunning(null);

        $this->assertSame('live', $this->statusOf('down-app', 'active', ['deploy_strategy' => 'php', 'deployment_status' => 'success']));
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
        $user = new User(['username' => $username]);
        $user->status = $status;

        return ProjectOverview::status($user, $details);
    }

    private function shipped(string $gitUrl): AppConfig
    {
        $config = AppConfigLocator::findShipped(new LocalAppConfigSource(), $gitUrl);
        $this->assertNotNull($config, $gitUrl);

        return $config;
    }
}
