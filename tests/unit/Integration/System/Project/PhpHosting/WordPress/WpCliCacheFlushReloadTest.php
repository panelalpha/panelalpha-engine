<?php

namespace Tests\Integration\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * A raw `cache flush` that succeeds on OpenLiteSpeed reloads the webserver in the background; a reload that cannot be
 * scheduled is logged and the flush still answers as WP-CLI did.
 */
class WpCliCacheFlushReloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_flush_on_openlitespeed_schedules_a_reload(): void
    {
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('openlitespeed');
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->once();

        $result = $this->cli($webserver)->run(['cache', 'flush']);

        $this->assertSame(0, $result['exit_code']);
    }

    public function test_a_reload_that_cannot_be_scheduled_is_only_logged(): void
    {
        Log::spy();
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andThrow(new \RuntimeException('webserver unknown'));

        $result = $this->cli($webserver)->run(['cache', 'flush']);

        $this->assertSame(['exit_code' => 0, 'stdout' => 'Success: The cache was flushed.', 'stderr' => ''], $result);
        Log::shouldHaveReceived('warning')->once()->with('Failed to schedule webserver reload after wp cache flush', Mockery::on(
            static fn (array $context): bool => $context['username'] === 'alice'
                && $context['error'] === 'webserver unknown'
                && array_slice($context['args'], 0, 2) === ['cache', 'flush'],
        ));
    }

    public function test_a_command_other_than_a_flush_reloads_nothing(): void
    {
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldNotReceive('scheduleWebserverReloadInBackground');
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('openlitespeed');

        $this->assertSame(0, $this->cli($webserver)->run(['cache', 'type'])['exit_code']);
    }

    private function cli(Webserver $webserver): WpCli
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('runWpCli')->andReturn(['exit_code' => 0, 'stdout' => 'Success: The cache was flushed.', 'stderr' => '']);
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($domain);
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        return WpCli::for($user, null, $system);
    }
}
