<?php

namespace Tests\Integration\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use App\Lib\Overlay\OverlayService;
use App\Lib\Overlay\OverlayStore;
use App\Models\Setting;
use App\System;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The global overlay reaches every project with a domain, and a project reports what its pages carry. */
class OverlayServiceGlobalTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
    }

    public function test_setting_the_global_overlay_re_renders_every_project_with_a_domain(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');
        $this->makeMainDomain($this->makeUser('bob'), 'bob.example.test');
        $this->makeUser('carol');
        $webserver = $this->webserver('nginx-proxy');
        $webserver->shouldReceive('rebuildDomainConfig')->twice();
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->once();
        $service = new OverlayService(new OverlayStore, $this->system($webserver));

        $service->setGlobal(new Overlay('<p>maintenance</p>', OverlayPosition::BodyEnd));

        $this->assertSame('<p>maintenance</p>', $service->global()?->html);
    }

    public function test_removing_the_global_overlay_forgets_it_and_warns_off_nginx_proxy(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');
        $store = new OverlayStore;
        $store->setGlobal(new Overlay('<p>old</p>'));
        Log::spy();
        $webserver = $this->webserver('apache');
        $webserver->shouldReceive('rebuildDomainConfig')->once();
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->once();
        $service = new OverlayService($store, $this->system($webserver));

        $service->removeGlobal();

        $this->assertNull($service->global());
        Log::shouldHaveReceived('warning')->with("Overlay changed, but the webserver is 'apache'; only nginx-proxy writes overlays into pages.");
    }

    public function test_project_state_reports_its_own_overlay_and_the_global_one(): void
    {
        $user = $this->makeUser('alice');
        $store = new OverlayStore;
        $store->setGlobal(new Overlay('<p>global</p>'));
        $store->setProject($user, new Overlay('<p>own</p>', OverlayPosition::HeadEnd), false);
        $service = new OverlayService($store, $this->system($this->webserver('nginx-proxy')));

        $this->assertSame([
            'html' => '<p>own</p>',
            'position' => OverlayPosition::HeadEnd->value,
            'show_global' => false,
            'global' => ['html' => '<p>global</p>', 'position' => OverlayPosition::BodyEnd->value],
            'active' => true,
        ], $service->projectState($user->fresh()));
    }

    public function test_a_project_without_overlays_is_inactive(): void
    {
        $service = new OverlayService(new OverlayStore, $this->system($this->webserver('nginx-proxy')));

        $this->assertSame(
            ['html' => null, 'position' => null, 'show_global' => true, 'global' => null, 'active' => false],
            $service->projectState($this->makeUser('alice'))
        );
    }

    private function webserver(string $current): Webserver&\Mockery\MockInterface
    {
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn($current);

        return $webserver;
    }

    private function system(Webserver $webserver): System
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        return $system;
    }
}
