<?php

namespace Tests\Unit\Overlay;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use App\Lib\Overlay\OverlayService;
use App\Models\User;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A project's overlay is a project setting, so its commands live under
 * project:overlay:* and need --project; the global one is system:overlay:*.
 */
class OverlayCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private MockInterface $overlays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->overlays = Mockery::mock(OverlayService::class);
        $this->app->instance(OverlayService::class, $this->overlays);
    }

    public function test_project_overlay_set_writes_that_projects_overlay(): void
    {
        $this->makeUser('shop');
        $this->overlays->shouldReceive('setProject')->once()->with(
            Mockery::on(fn (User $u) => $u->username === 'shop'),
            Mockery::on(fn (Overlay $o) => $o->html === '<p>x</p>' && $o->position === OverlayPosition::HeadEnd),
            false,
        );

        $this->artisan('project:overlay:set', ['--project' => 'shop', '--html' => '<p>x</p>', '--position' => 'head_end', '--hide-global' => true])
            ->expectsOutputToContain("Overlay set for project 'shop'.")
            ->assertExitCode(0);
    }

    public function test_project_overlay_commands_refuse_without_a_project(): void
    {
        $this->overlays->shouldNotReceive('setProject', 'removeProject', 'projectState', 'setGlobal', 'removeGlobal');

        foreach (['project:overlay:set' => ['--html' => '<p>x</p>'], 'project:overlay:show' => [], 'project:overlay:unset' => []] as $command => $options) {
            $this->artisan($command, $options)->expectsOutputToContain('--project is required.')->assertExitCode(1);
        }
    }

    public function test_project_overlay_commands_refuse_an_unknown_project(): void
    {
        $this->overlays->shouldNotReceive('setProject', 'removeProject', 'projectState');

        $this->artisan('project:overlay:unset', ['--project' => 'nope'])
            ->expectsOutputToContain("Project 'nope' not found.")
            ->assertExitCode(1);
    }

    public function test_project_overlay_unset_removes_only_that_projects_overlay(): void
    {
        $this->makeUser('shop');
        $this->overlays->shouldReceive('removeProject')->once()->with(Mockery::on(fn (User $u) => $u->username === 'shop'));
        $this->overlays->shouldNotReceive('removeGlobal');

        $this->artisan('project:overlay:unset', ['--project' => 'shop'])->assertExitCode(0);
    }

    public function test_system_overlay_set_writes_the_global_overlay(): void
    {
        $this->overlays->shouldReceive('setGlobal')->once()->with(Mockery::on(fn (Overlay $o) => $o->html === '<b>all</b>' && $o->position === OverlayPosition::BodyEnd));

        $this->artisan('system:overlay:set', ['--html' => '<b>all</b>'])
            ->expectsOutputToContain('Global overlay set.')
            ->assertExitCode(0);
    }

    public function test_system_overlay_set_refuses_an_unknown_position(): void
    {
        $this->overlays->shouldNotReceive('setGlobal');

        $this->artisan('system:overlay:set', ['--html' => '<b>all</b>', '--position' => 'footer'])
            ->expectsOutputToContain('--position must be one of: body_end, head_end.')
            ->assertExitCode(1);
    }

    public function test_system_overlay_show_and_unset_act_on_the_global_overlay(): void
    {
        $this->overlays->shouldReceive('global')->once()->andReturn(new Overlay('<b>all</b>'));
        $this->overlays->shouldReceive('removeGlobal')->once();

        $this->artisan('system:overlay:show', ['--json' => true])->expectsOutputToContain('"html": "<b>all</b>"')->assertExitCode(0);
        $this->artisan('system:overlay:unset')->expectsOutputToContain('Global overlay removed.')->assertExitCode(0);
    }
}
