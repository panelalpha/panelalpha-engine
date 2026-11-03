<?php

namespace Tests\Unit\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use App\Lib\Overlay\OverlayStore;
use App\Models\Setting;
use App\Models\User;
use Tests\TestCase;

class OverlayStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings([OverlayStore::GLOBAL_SETTING => null]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_the_global_overlay_comes_before_the_projects_own(): void
    {
        $store = new OverlayStore;
        $store->setGlobal(new Overlay('<p>global</p>'));
        $user = $this->project();
        $store->setProject($user, new Overlay('<p>own</p>', OverlayPosition::HeadEnd), true);

        $this->assertEquals(
            [new Overlay('<p>global</p>'), new Overlay('<p>own</p>', OverlayPosition::HeadEnd)],
            $store->effective($user),
        );
    }

    public function test_a_project_can_hide_the_global_overlay(): void
    {
        $store = new OverlayStore;
        $store->setGlobal(new Overlay('<p>global</p>'));
        $user = $this->project();

        $store->setProject($user, null, false);

        $this->assertFalse($store->showsGlobal($user));
        $this->assertSame([], $store->effective($user));
    }

    public function test_replacing_the_project_settings_drops_what_is_not_given_again(): void
    {
        $store = new OverlayStore;
        $user = $this->project();
        $store->setProject($user, new Overlay('<p>own</p>'), false);

        $store->setProject($user, null, true);

        $this->assertNull($store->project($user));
        $this->assertTrue($store->showsGlobal($user));
        $this->assertSame(['mysql_prefix' => 'shop_'], $user->getDetails());
    }

    public function test_removing_the_global_overlay_leaves_none(): void
    {
        $store = new OverlayStore;
        $store->setGlobal(new Overlay('<p>global</p>'));

        $store->forgetGlobal();

        $this->assertNull($store->global());
        $this->assertSame([], $store->effective($this->project()));
    }

    private function project(): User
    {
        $user = new class extends User
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $user->username = 'shop';
        $user->details = ['mysql_prefix' => 'shop_'];

        return $user;
    }
}
