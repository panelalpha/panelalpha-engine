<?php

namespace Tests\Unit\Domains;

use App\Jobs\RebuildDomainConfigs;
use App\Lib\Domains\NamingBaseSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** Changing a base projects are named under re-renders every vhost, whose cookie rules list it. */
class NamingBaseSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Setting::setRuntimeSettings(['default_wildcard_domain' => 'old.example.com', 'cert_domain' => '']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_new_base_is_stored_and_every_vhost_rebuilt(): void
    {
        NamingBaseSetting::save('default_wildcard_domain', 'new.example.com');

        $this->assertSame('new.example.com', Setting::get('default_wildcard_domain'));
        Bus::assertDispatchedTimes(RebuildDomainConfigs::class, 1);
    }

    public function test_the_same_value_rebuilds_nothing(): void
    {
        NamingBaseSetting::save('default_wildcard_domain', 'old.example.com');
        NamingBaseSetting::save('cert_domain', '');

        Bus::assertNotDispatched(RebuildDomainConfigs::class);
    }

    public function test_an_emptied_base_is_removed_and_rebuilds(): void
    {
        NamingBaseSetting::save('default_wildcard_domain', '');

        $this->assertNull(Setting::get('default_wildcard_domain'));
        Bus::assertDispatchedTimes(RebuildDomainConfigs::class, 1);
    }

    public function test_only_the_naming_bases_are_covered(): void
    {
        $this->assertTrue(NamingBaseSetting::covers('default_wildcard_domain'));
        $this->assertTrue(NamingBaseSetting::covers('cert_domain'));
        $this->assertFalse(NamingBaseSetting::covers('cert_email'));
    }
}
