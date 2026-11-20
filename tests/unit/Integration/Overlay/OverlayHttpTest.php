<?php

namespace Tests\Integration\Overlay;

use App\Http\Middleware\Authenticate;
use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayService;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** GET, PUT and DELETE on the global and the per-project overlay: what each asks the overlay service to do, and the state it answers with. */
class OverlayHttpTest extends TestCase
{
    use InMemoryDatabase;

    private OverlayService $overlays;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeUser('shop');
        $this->overlays = new class extends OverlayService
        {
            /** @var list<string> */
            public array $calls = [];

            private ?Overlay $global = null;

            /** @var array<string, array{0: ?Overlay, 1: bool}> */
            private array $projects = [];

            public function __construct() {}

            public function setGlobal(Overlay $overlay): void
            {
                $this->calls[] = 'set global';
                $this->global = $overlay;
            }

            public function removeGlobal(): void
            {
                $this->calls[] = 'remove global';
                $this->global = null;
            }

            public function setProject(User $user, ?Overlay $overlay, bool $showGlobal): void
            {
                $this->calls[] = "set {$user->username}";
                $this->projects[$user->username] = [$overlay, $showGlobal];
            }

            public function removeProject(User $user): void
            {
                $this->calls[] = "remove {$user->username}";
                unset($this->projects[$user->username]);
            }

            public function projectState(User $user): array
            {
                [$own, $showGlobal] = $this->projects[$user->username] ?? [null, true];

                return [
                    'html' => $own?->html,
                    'position' => $own?->position->value,
                    'show_global' => $showGlobal,
                    'global' => $this->global?->toArray(),
                    'active' => $own !== null || ($showGlobal && $this->global !== null),
                ];
            }

            public function global(): ?Overlay
            {
                return $this->global;
            }
        };
        $this->app->instance(OverlayService::class, $this->overlays);
    }

    public function test_the_global_overlay_is_set_shown_and_removed(): void
    {
        $this->getJson('/api/system/overlay')->assertOk()->assertExactJson(['overlay' => null]);

        $this->putJson('/api/system/overlay', ['html' => '<p>Maintenance tonight</p>', 'position' => 'head_end'])
            ->assertOk()
            ->assertExactJson(['overlay' => ['html' => '<p>Maintenance tonight</p>', 'position' => 'head_end']]);
        $this->getJson('/api/system/overlay')->assertOk()->assertJsonPath('overlay.html', '<p>Maintenance tonight</p>');

        $this->deleteJson('/api/system/overlay')->assertOk()->assertExactJson(['overlay' => null]);
        $this->assertSame(['set global', 'remove global'], $this->overlays->calls);
    }

    public function test_a_global_overlay_without_html_is_refused(): void
    {
        $this->putJson('/api/system/overlay', ['html' => ''])->assertUnprocessable()->assertJsonValidationErrors('html');

        $this->assertSame([], $this->overlays->calls);
    }

    public function test_a_project_overlay_is_set_shown_and_removed(): void
    {
        $this->getJson('/api/projects/shop/overlay')->assertOk()->assertJsonPath('active', false);

        $this->putJson('/api/projects/shop/overlay', ['html' => '<b>Staging</b>', 'show_global' => false])
            ->assertOk()
            ->assertJsonPath('html', '<b>Staging</b>')
            ->assertJsonPath('position', 'body_end')
            ->assertJsonPath('show_global', false)
            ->assertJsonPath('active', true);

        $this->deleteJson('/api/projects/shop/overlay')->assertOk()->assertJsonPath('html', null)->assertJsonPath('show_global', true);
        $this->assertSame(['set shop', 'remove shop'], $this->overlays->calls);
    }

    public function test_an_unknown_project_has_no_overlay(): void
    {
        $this->getJson('/api/projects/nobody/overlay')->assertNotFound();
        $this->putJson('/api/projects/nobody/overlay', ['html' => '<b>x</b>'])->assertNotFound();
        $this->deleteJson('/api/projects/nobody/overlay')->assertNotFound();

        $this->assertSame([], $this->overlays->calls);
    }
}
