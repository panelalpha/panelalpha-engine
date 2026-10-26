<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Cache;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Services\Webserver;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named cache flush: success body, failure as Exception, and the
 * OpenLiteSpeed reload side effect via a fake System/webserver.
 */
class CacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_flush_returns_message_and_passes_default_path(): void
    {
        $sequence = [];
        $cache = $this->cacheWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "Success: The cache was flushed.\n", 'stderr' => ''],
            ],
        );

        $body = $cache->flush();

        $this->assertSame(
            ['cache', 'flush', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame('eval', $sequence[1][0]);
        $this->assertStringContainsString("do_action('litespeed_purge_all')", $sequence[1][1]);
        $this->assertSame('--path=/home/alice/example.test/public_html', $sequence[1][2]);
        $this->assertSame(['message' => 'Success: The cache was flushed.', 'page_caches' => 'flushed'], $body);
    }

    public function test_with_plugins_skipped_the_page_cache_step_is_skipped(): void
    {
        $sequence = [];
        $cache = $this->cacheWithFakeRunner($sequence, skipPlugins: true);

        $body = $cache->flush();

        $this->assertCount(1, $sequence);
        $this->assertSame('skipped', $body['page_caches']);
    }

    public function test_flush_with_explicit_path(): void
    {
        $sequence = [];
        $cache = $this->cacheWithFakeRunner($sequence, path: '/home/alice/other.test/public_html');

        $cache->flush();

        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[0][2]);
    }

    public function test_flush_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $cache = $this->cacheWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => 'Error: Could not flush cache.',
                    'stderr' => '',
                ],
            ],
        );

        try {
            $cache->flush();
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Could not flush cache.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_successful_flush_on_openlitespeed_schedules_webserver_reload(): void
    {
        $sequence = [];
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->once()->andReturn('openlitespeed');
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->once();
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        $cache = $this->cacheWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'Success: The cache was flushed.', 'stderr' => ''],
            ],
            system: $system,
        );

        $body = $cache->flush();

        $this->assertSame('Success: The cache was flushed.', $body['message']);
    }

    public function test_successful_flush_on_nginx_does_not_schedule_reload(): void
    {
        $sequence = [];
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->once()->andReturn('nginx');
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->never();
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        $cache = $this->cacheWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'Success: The cache was flushed.', 'stderr' => ''],
            ],
            system: $system,
        );

        $body = $cache->flush();

        $this->assertSame('Success: The cache was flushed.', $body['message']);
    }

    public function test_failed_flush_does_not_schedule_reload(): void
    {
        $sequence = [];
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->never();
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->never();
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->never();

        $cache = $this->cacheWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => 'Error: flush failed', 'stderr' => ''],
            ],
            system: $system,
        );

        try {
            $cache->flush();
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: flush failed', $e->getMessage());
        }
    }

    /**
     * @param list<list<string>> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function cacheWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?System $system = null,
        ?string $path = null,
        bool $skipPlugins = false,
    ): Cache {
        if ($system === null) {
            // Default host is not OpenLiteSpeed so successful flushes do not
            // construct a real System (and Log facade) in this unit test.
            $webserver = Mockery::mock(Webserver::class);
            $webserver->shouldReceive('getCurrentWebserver')->andReturn('nginx');
            $webserver->shouldReceive('scheduleWebserverReloadInBackground')->never();
            $system = Mockery::mock(System::class);
            $system->shouldReceive('webserver')->andReturn($webserver);
        }

        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = $args;
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Cache(WpCli::for($user, $path, $system, skipPlugins: $skipPlugins));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
