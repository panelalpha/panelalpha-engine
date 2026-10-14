<?php

namespace Tests\Unit\Console;

use App\Console\Wizard\Sections\PrewarmSection;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Tests\TestCase;

/**
 * `pae configure prewarm` writes DEPLOY_PREWARM_* to `.env`, and the plan the
 * weekly prewarm runs follows it.
 */
class PrewarmSectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/prewarm-section-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', "APP_ENV=testing\nDEPLOY_PREWARM_IMAGES=\n");
        $this->app->useEnvironmentPath($this->dir);
        $this->app->loadEnvironmentFrom('.env');
        config(['deploy.prewarm_images' => '', 'deploy.prewarm_budget' => '', 'deploy.prewarm_reserve' => '']);
        // Any artisan call earlier in the process switches prompts to their
        // fallbacks, which answer with defaults and never read the faked keys,
        // and fallbackWhen() can only turn that on.
        (new \ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        // fake() leaves prompts interactive for the rest of the process, and the
        // next test's prompt would wait on a terminal that is not there.
        Prompt::interactive(false);
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_selecting_an_image_writes_it_and_the_plan_follows(): void
    {
        $this->assertSame([], HostPrewarmPlan::catalog(), 'nothing is warmed by default');
        $first = $this->firstListed();

        // Menu → Images; tick the first row; accept; Review and save; Write? yes; pause.
        Prompt::fake([Key::ENTER, Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);

        $section = new PrewarmSection();
        $this->assertSame(0, $section->run(false));

        $this->assertStringContainsString("DEPLOY_PREWARM_IMAGES={$first}\n", $this->env());
        $this->assertSame([$first], array_column(HostPrewarmPlan::catalog(), 'id'));
        $this->assertNotSame([], $section->receipt());
    }

    public function test_budget_and_reserve_are_written(): void
    {
        // Menu → Budget; type 2G. The menu now defaults to save, one up is Reserve; type 20G.
        // Then save, yes, pause.
        Prompt::fake([
            Key::DOWN, Key::ENTER, '2', 'G', Key::ENTER,
            Key::UP, Key::ENTER, '2', '0', 'G', Key::ENTER,
            Key::ENTER, Key::ENTER, Key::ENTER,
        ]);

        $this->assertSame(0, (new PrewarmSection())->run(false));

        $env = $this->env();
        $this->assertStringContainsString("DEPLOY_PREWARM_BUDGET=2G\n", $env);
        $this->assertStringContainsString("DEPLOY_PREWARM_RESERVE=20G\n", $env);
        $this->assertSame(2 * 1073741824, HostPrewarmPlan::budgetBytes());
        $this->assertSame(20 * 1073741824, HostPrewarmPlan::reserveBytes());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Prompt::fake([Key::ENTER, Key::SPACE, Key::ENTER, Key::ENTER, Key::ENTER]);

        $this->assertSame(0, (new PrewarmSection())->run(true));

        $this->assertStringContainsString("DEPLOY_PREWARM_IMAGES=\n", $this->env());
        $this->assertSame([], HostPrewarmPlan::selected());
    }

    /** The section groups by runtime; the first row is the first runtime's first image. */
    private function firstListed(): string
    {
        return HostPrewarmPlan::available()[0]['id'];
    }

    private function env(): string
    {
        return (string) file_get_contents($this->dir . '/.env');
    }
}
