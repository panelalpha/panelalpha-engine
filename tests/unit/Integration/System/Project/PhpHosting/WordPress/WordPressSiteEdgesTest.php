<?php

namespace Tests\Integration\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\AutoUpdate;
use App\System\Project\PhpHosting\WordPress\Cache;
use App\System\Project\PhpHosting\WordPress\Maintenance;
use App\System\Project\PhpHosting\WordPress\Sso;
use App\System\Project\PhpHosting\WordPress\WordPress;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * A WordPress site with nowhere to run is refused by name, and a page-cache purge that cannot even start is a failed
 * purge, never a failed flush.
 */
class WordPressSiteEdgesTest extends TestCase
{
    /** @var list<list<string>> */
    private array $calls = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_an_account_without_a_main_domain_has_no_install_path(): void
    {
        $user = $this->user(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Project 'alice' has no main domain.");

        WpCli::for($user, null, $this->system())->resolvePath();
    }

    public function test_a_page_cache_purge_that_cannot_run_is_reported_as_failed(): void
    {
        Log::spy();
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');

        $body = (new Cache(WpCli::for($this->user($domain, purgeThrows: true), null, $this->system())))->flush();

        $this->assertSame(['message' => 'Success: The cache was flushed.', 'page_caches' => 'failed'], $body);
        $this->assertSame(['cache', 'flush'], array_slice($this->calls[0], 0, 2));
    }

    public function test_the_site_hands_each_area_the_same_wp_cli(): void
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');
        $user = $this->user($domain);
        $wordPress = new WordPress($user, WpCli::for($user, null, $this->system()));

        $this->assertInstanceOf(Maintenance::class, $wordPress->maintenance());
        $this->assertInstanceOf(AutoUpdate::class, $wordPress->autoUpdate());
        $this->assertInstanceOf(Sso::class, $wordPress->sso());
        $this->assertSame('/home/alice/example.test/public_html', $wordPress->cli()->resolvePath());
    }

    private function user(?Domain $mainDomain, bool $purgeThrows = false): User
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args) use ($purgeThrows): array {
            $this->calls[] = $args;
            if ($purgeThrows && $args[0] === 'eval') {
                throw new \RuntimeException('container is gone');
            }

            return ['exit_code' => 0, 'stdout' => 'Success: The cache was flushed.', 'stderr' => ''];
        });
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($mainDomain);

        return $user;
    }

    private function system(): System
    {
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('nginx');
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        return $system;
    }
}
