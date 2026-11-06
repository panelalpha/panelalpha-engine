<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Models\Setting;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * What GET /system/info reports as the domains new projects are named under,
 * read from the same settings the allocator walks at create time.
 */
class ProjectDomainBasesTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @param array<string, ?string> $settings */
    private function engine(array $settings): void
    {
        Setting::setRuntimeSettings($settings + [
            'default_wildcard_domain' => null,
            'cert_domain' => null,
            'default_ipv4' => null,
            DomainPlan::SETTING_DEFAULT_TUNNEL => null,
        ]);
    }

    public function test_a_public_engine_reports_panelalpha_online_first(): void
    {
        $this->engine(['default_ipv4' => TestNetwork::public(7)]);

        $this->assertSame([
            ['base' => 'panelalpha.online', 'source' => DomainPlan::SOURCE_PANELALPHA_ONLINE, 'suffixed' => true, 'publicly_resolvable' => true],
            ['base' => str_replace('.', '-', TestNetwork::public(7)).'.panelalpha.direct', 'source' => DomainPlan::SOURCE_PANELALPHA_DIRECT, 'suffixed' => false, 'publicly_resolvable' => true],
            ['base' => 'local', 'source' => DomainPlan::SOURCE_LOCAL, 'suffixed' => false, 'publicly_resolvable' => false],
        ], DomainAllocator::projectDomainBases());
    }

    public function test_the_configured_base_domain_is_read_from_its_setting(): void
    {
        $this->engine(['default_wildcard_domain' => 'sites.example.com', 'default_ipv4' => TestNetwork::public(7)]);

        $first = DomainAllocator::projectDomainBases()[0];

        $this->assertSame('sites.example.com', $first['base']);
        $this->assertNull($first['publicly_resolvable'], 'the operator\'s DNS is not the engine\'s to vouch for');
    }

    public function test_a_private_engine_reports_a_direct_name_only_its_lan_can_open(): void
    {
        $this->engine(['default_ipv4' => TestNetwork::private(4)]);

        $bases = DomainAllocator::projectDomainBases();

        $this->assertSame([DomainPlan::SOURCE_PANELALPHA_DIRECT, DomainPlan::SOURCE_LOCAL], array_column($bases, 'source'));
        $this->assertFalse($bases[0]['publicly_resolvable']);
    }

    public function test_the_default_tunnel_setting_is_honoured(): void
    {
        $this->engine([DomainPlan::SETTING_DEFAULT_TUNNEL => DomainPlan::TUNNEL_NONE, 'default_ipv4' => TestNetwork::public(7)]);

        $this->assertNotContains(DomainPlan::SOURCE_PANELALPHA_ONLINE, array_column(DomainAllocator::projectDomainBases(), 'source'));
    }
}
