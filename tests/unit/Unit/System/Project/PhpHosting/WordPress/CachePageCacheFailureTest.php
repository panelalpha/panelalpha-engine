<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\Cache;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * A page-cache purge that fails does not fail the flush: it is logged and
 * reported as failed. Needs the Log facade, hence the app TestCase.
 */
class CachePageCacheFailureTest extends TestCase
{
    public function test_a_failed_page_cache_purge_is_logged_and_reported(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Best-effort WordPress page cache flush failed'
                && $context['output'] === 'PHP Fatal error: rocket_clean_domain()',
        );

        $results = [
            ['exit_code' => 0, 'stdout' => 'Success: The cache was flushed.', 'stderr' => ''],
            ['exit_code' => 255, 'stdout' => '', 'stderr' => 'PHP Fatal error: rocket_clean_domain()'],
        ];
        $call = 0;
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('runWpCli')->twice()->andReturnUsing(function () use (&$call, $results): array {
            return $results[$call++];
        });
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($domain);
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('nginx');
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        $body = (new Cache(WpCli::for($user, null, $system)))->flush();

        $this->assertSame(['message' => 'Success: The cache was flushed.', 'page_caches' => 'failed'], $body);
    }
}
