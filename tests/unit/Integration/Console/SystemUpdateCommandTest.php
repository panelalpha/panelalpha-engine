<?php

namespace Tests\Integration\Console;

use App\System;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeUpdateCheckSystem;
use Tests\Support\HubReleases;
use Tests\TestCase;

/**
 * pae system:update: --check says what an update would install, and an update starts only after a confirmation or
 * --force and never while one runs. Whatever the check says, the updater is what refuses an older release or changes
 * nothing, as for PUT /system/update.
 */
class SystemUpdateCommandTest extends TestCase
{
    private const QUESTION = 'Update the engine to 2.4.0? The engine restarts, and the API and MCP disconnect for about a minute; sites keep running.';

    private FakeUpdateCheckSystem $system;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['system.version' => '2.3.1']);
        $this->system = new FakeUpdateCheckSystem;
        $this->app->instance(System::class, $this->system);
    }

    public function test_check_names_both_releases_and_starts_nothing(): void
    {
        HubReleases::fake(['2.4.0']);

        $this->artisan('system:update --check')
            ->expectsOutput('Running:   2.3.1')
            ->expectsOutput('Available: 2.4.0, published 2026-11-19')
            ->expectsOutput('Changes:   https://engine.panelalpha.com/changelog')
            ->expectsOutput('An update is available.')
            ->assertExitCode(0);
        $this->assertSame(0, $this->system->started);
    }

    public function test_check_uses_the_kept_answer_until_refresh(): void
    {
        HubReleases::fake(['2.4.0', '2.3.1']);

        $this->artisan('system:update --check')->assertExitCode(0);
        $this->artisan('system:update --check')
            ->expectsOutputToContain('(kept answer; --refresh checks again)')
            ->assertExitCode(0);
        $this->artisan('system:update --check --refresh')
            ->expectsOutput('Already up to date.')
            ->assertExitCode(0);
        $this->assertSame(2, HubReleases::asked());
    }

    public function test_check_as_json(): void
    {
        HubReleases::fake(['2.4.0']);

        $this->artisan('system:update --check --json')
            ->expectsOutputToContain('"status":"update_available","update_available":true')
            ->assertExitCode(0);
    }

    public function test_a_hub_that_fails_fails_the_check_and_leaves_the_update_to_the_updater(): void
    {
        HubReleases::fake([null, null]);

        $this->artisan('system:update --check')
            ->expectsOutput('Available: unknown')
            ->assertExitCode(1);
        $this->assertSame(0, $this->system->started);

        $this->artisan('system:update --force')
            ->expectsOutput('The PanelAlpha Hub answered the release list with Server Error. The updater reads the newest release itself.')
            ->assertExitCode(0);
        $this->assertSame(1, $this->system->started);
    }

    public function test_an_update_starts_after_a_confirmation(): void
    {
        HubReleases::fake(['2.4.0', '2.4.0']);

        $this->artisan('system:update')
            ->expectsConfirmation(self::QUESTION, 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);
        $this->assertSame(0, $this->system->started);

        $this->artisan('system:update')
            ->expectsConfirmation(self::QUESTION, 'yes')
            ->expectsOutputToContain('The engine update has started.')
            ->assertExitCode(0);
        $this->assertSame(1, $this->system->started);
    }

    public function test_an_update_checks_afresh_and_force_skips_the_question(): void
    {
        HubReleases::fake(['2.4.0', '2.4.0']);
        $this->artisan('system:update --check')->assertExitCode(0);

        $this->artisan('system:update --force')
            ->expectsOutput('Available: 2.4.0, published 2026-11-19')
            ->assertExitCode(0);

        $this->assertSame(2, HubReleases::asked());
        $this->assertSame(1, $this->system->started);
    }

    public function test_without_a_terminal_an_update_needs_force(): void
    {
        HubReleases::fake(['2.4.0']);

        $this->artisan('system:update --no-interaction')->assertExitCode(1);
        $this->assertSame(0, $this->system->started);
    }

    public function test_with_no_terminal_and_no_force_an_update_is_refused_rather_than_cancelled(): void
    {
        HubReleases::fake(['2.4.0']);
        $tester = new CommandTester($this->app->make(Kernel::class)->all()['system:update']);

        $tester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame("This change needs confirmation: pass --force to apply it without a prompt.\n", $tester->getErrorOutput());
        $this->assertStringNotContainsString('Cancelled.', $tester->getDisplay());
        $this->assertSame(0, $this->system->started);
    }

    public function test_no_newer_version_still_runs_the_updater_as_the_api_does(): void
    {
        HubReleases::fake(['2.3.1', '2.3.0']);

        $this->artisan('system:update --force')
            ->expectsOutput('No newer version is published. The updater installs a fix published under the running version, and otherwise changes nothing.')
            ->assertExitCode(0);
        $this->artisan('system:update --force')
            ->expectsOutput('The available release is older than the running one. The updater refuses it and changes nothing.')
            ->assertExitCode(0);
        $this->assertSame(2, $this->system->started);
    }

    public function test_no_newer_version_asks_whether_to_run_the_updater(): void
    {
        HubReleases::fake(['2.3.1']);

        $this->artisan('system:update')
            ->expectsConfirmation('Run the engine updater? The engine restarts only if it installs something; sites keep running.', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);
        $this->assertSame(0, $this->system->started);
    }

    public function test_a_running_update_is_not_started_twice(): void
    {
        HubReleases::fake([]);
        $this->system->running = true;

        $this->artisan('system:update --force')->assertExitCode(1);
        $this->assertSame(0, $this->system->started);
        $this->assertSame(0, HubReleases::asked());
    }

    public function test_status_says_there_was_no_update_yet(): void
    {
        HubReleases::fake([]);

        $this->artisan('system:update --status')
            ->expectsOutput('The engine has never been updated from here.')
            ->assertExitCode(0);
        $this->assertSame(0, HubReleases::asked());
    }
}
