<?php

namespace Tests\Unit\Console;

use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `settings:set` is the way an operator writes default_tunnel without the
 * wizard, so it has to refuse a value the ladder would only ignore -- silently
 * accepting one would read as "the setting is on" while nothing changed.
 */
class SettingsSetCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings([DomainPlan::SETTING_DEFAULT_TUNNEL => '']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_it_refuses_an_unknown_tunnel(): void
    {
        $exit = Artisan::call('settings:set', [
            'name' => DomainPlan::SETTING_DEFAULT_TUNNEL,
            'value' => 'cloudflare',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('panelalpha, none', Artisan::output());
        $this->assertSame('', (string) Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL));
    }

    public function test_it_accepts_both_tunnels(): void
    {
        foreach (DomainPlan::TUNNELS as $value) {
            $exit = Artisan::call('settings:set', [
                'name' => DomainPlan::SETTING_DEFAULT_TUNNEL,
                'value' => $value,
            ]);

            $this->assertSame(0, $exit);
            $this->assertSame($value, Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL));
        }
    }

    public function test_other_settings_are_untouched_by_the_check(): void
    {
        Setting::setRuntimeSettings([DomainPlan::SETTING_DEFAULT_TUNNEL => '', 'cert_domain' => '']);

        $this->assertSame(0, Artisan::call('settings:set', ['name' => 'cert_domain', 'value' => 'engine.example.com']));
        $this->assertSame('engine.example.com', Setting::get('cert_domain'));
    }
}
