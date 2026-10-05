<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Deploy\HealthReportCommand;
use App\Models\User;
use App\System\Project\Dind\AppHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * engine#633: what the six-hourly sweep counts as not serving, and what it
 * leaves alone.
 */
class HealthReportSweepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach (['2014_10_12_000000_create_users_table.php', '2025_09_29_121922_add_status_to_users_table.php'] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_silence_with_no_failed_check_is_not_serving(): void
    {
        $silent = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]],
            AppHealth::DETAIL_CHECKS => [],
        ];

        $this->assertTrue(HealthReportCommand::notServing($silent));
        $this->assertTrue(HealthReportCommand::notServing([AppHealth::DETAIL_CHECKS => [['id' => 'app-stopped', 'severity' => 'error']]]));
        $this->assertFalse(HealthReportCommand::notServing([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => true,
            AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]],
        ]));
        // A worker with nothing to probe is not silence.
        $this->assertFalse(HealthReportCommand::notServing([AppHealth::DETAIL_CHECKED => false, AppHealth::DETAIL_HEALTHY => null]));
    }

    public function test_a_project_stopped_on_request_is_skipped(): void
    {
        $user = User::create([
            'username' => 'stoppedapp',
            'domain' => 'stoppedapp.example.test',
            'details' => ['template' => 'dind'],
        ]);
        $user->markAppStoppedByRequest(true);
        $this->assertTrue($user->fresh()->isAppStoppedByRequest());

        Artisan::call('project:health:report', ['project' => 'stoppedapp', '--local' => true]);

        $this->assertStringContainsString('Swept 0 project(s)', Artisan::output());
    }

    public function test_starting_the_app_again_clears_the_flag(): void
    {
        $user = User::create(['username' => 'restarted', 'domain' => 'restarted.example.test', 'details' => ['template' => 'dind']]);
        $user->markAppStoppedByRequest(true);
        $user->fresh()->markAppStoppedByRequest(false);

        $this->assertFalse($user->fresh()->isAppStoppedByRequest());
        $this->assertArrayNotHasKey('app_stopped_by_request', array_filter($user->fresh()->getDetails(), static fn ($v) => $v !== null));
    }
}
