<?php

namespace Tests\Integration\Console\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** settings:get, :exists and :set read and write the settings table, and report a table they cannot reach. */
class SettingsCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::flushCache();
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        parent::tearDown();
    }

    public function test_get_and_exists_read_a_stored_setting(): void
    {
        Setting::set('cert_email', 'ops@example.test');

        $this->artisan('settings:get cert_email')->expectsOutput('ops@example.test')->assertExitCode(0);
        $this->artisan('settings:exists cert_email')->assertExitCode(0);
        $this->artisan('settings:exists never_set')->assertExitCode(1);
    }

    public function test_get_and_set_report_an_unreachable_settings_table(): void
    {
        Schema::drop('settings');

        $this->artisan('settings:get cert_email')
            ->expectsOutputToContain('[ERROR] ')
            ->assertExitCode(1);
        $this->artisan('settings:set cert_email ops@example.test')
            ->expectsOutputToContain('[ERROR] ')
            ->assertExitCode(1);
    }
}
