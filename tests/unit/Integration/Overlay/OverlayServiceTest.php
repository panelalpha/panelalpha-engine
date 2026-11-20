<?php

namespace Tests\Integration\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayService;
use App\Lib\Overlay\OverlayStore;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Services\Webserver;
use Mockery;
use Tests\TestCase;

class OverlayServiceTest extends TestCase
{
    public function test_a_project_overlay_re_renders_each_of_its_vhosts_then_reloads_once(): void
    {
        $user = $this->project(['shop.example.test', 'www.shop.example.test']);
        $store = Mockery::mock(OverlayStore::class);
        $store->shouldReceive('setProject')->once()->with($user, Mockery::type(Overlay::class), false);
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('nginx-proxy');
        $webserver->shouldReceive('rebuildDomainConfig')->twice();
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->once();

        (new OverlayService($store, $this->system($webserver)))->setProject($user, new Overlay('<p>x</p>'), false);
    }

    public function test_one_vhost_failing_to_render_does_not_stop_the_rest(): void
    {
        $user = $this->project(['a.example.test', 'b.example.test']);
        $store = Mockery::mock(OverlayStore::class);
        $store->shouldReceive('setProject');
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('nginx-proxy');
        $webserver->shouldReceive('rebuildDomainConfig')->once()->andThrow(new \RuntimeException('disk full'));
        $webserver->shouldReceive('rebuildDomainConfig')->once();
        $webserver->shouldReceive('scheduleWebserverReloadInBackground')->once();

        (new OverlayService($store, $this->system($webserver)))->removeProject($user);
    }

    public function test_removing_an_absent_global_overlay_touches_nothing(): void
    {
        $store = Mockery::mock(OverlayStore::class);
        $store->shouldReceive('global')->andReturn(null);
        $store->shouldNotReceive('forgetGlobal');
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldNotReceive('scheduleWebserverReloadInBackground');

        (new OverlayService($store, $this->system($webserver)))->removeGlobal();
    }

    private function system(Webserver $webserver): System
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        return $system;
    }

    /**
     * @param  list<string>  $domainNames
     */
    private function project(array $domainNames): User
    {
        $user = new User;
        $user->username = 'shop';
        $user->details = ['template' => 'php'];
        $user->setRelation('domains', collect(array_map(static function (string $name): Domain {
            $domain = new Domain;
            $domain->domain = $name;

            return $domain;
        }, $domainNames)));

        return $user;
    }
}
