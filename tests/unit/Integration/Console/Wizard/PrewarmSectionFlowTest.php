<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\PrewarmSection;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Key;
use Tests\Support\InMemoryDatabase;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure prewarm`: the paths that do not end in a write. */
class PrewarmSectionFlowTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private string $first = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->startWizardScreen("APP_KEY=base64:dGVzdA==\nDEPLOY_PREWARM_IMAGES=\n");
        config(['deploy.prewarm_images' => '', 'deploy.prewarm_budget' => '', 'deploy.prewarm_reserve' => '']);
        $this->first = HostPrewarmPlan::available()[0]['id'];
    }

    protected function tearDown(): void
    {
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_selecting_none_then_leaving_asks_before_throwing_it_away(): void
    {
        config(['deploy.prewarm_images' => $this->first]);
        $this->keys([...self::row(1), Key::CTRL_E, Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER, 'y', Key::ENTER]);

        $section = new PrewarmSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('Select none — warm nothing (the default)');
        $this->assertScreenShows('Your change: 0 of');
        $this->assertScreenShows('Leave without saving? Your changes will be thrown away.');
        $this->assertSame([$this->first], HostPrewarmPlan::selected());
        $this->assertSame([], $section->receipt());
    }

    public function test_sizes_are_checked_and_a_declined_save_keeps_them(): void
    {
        $this->keys([
            ...self::row(1), 'lots'.Key::ENTER, self::typed('none', 'lots'),
            ...self::row(2), 'x'.Key::ENTER, self::typed('20G', 'x'),
            Key::ENTER, 'n', Key::ENTER, Key::ENTER,
            Key::CTRL_E, Key::ENTER, 'y', Key::ENTER,
        ]);

        $section = new PrewarmSection();
        $section->run(false);

        $this->assertScreenShows('A size like 6G or 512M, `none`, or empty for the default.');
        $this->assertScreenShows('A size like 10G or 512M, or empty for the default.');
        $this->assertScreenShows('DEPLOY_PREWARM_BUDGET=none');
        $this->assertScreenShows('Nothing was written. Your changes are still here.');
        $this->assertStringNotContainsString('DEPLOY_PREWARM_BUDGET', $this->envContents());
    }

    public function test_saving_what_the_host_already_uses_writes_nothing(): void
    {
        $this->keys([...self::row(3), Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = new PrewarmSection();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('Nothing to save: this is what the host already uses.');
        $this->assertSame("APP_KEY=base64:dGVzdA==\nDEPLOY_PREWARM_IMAGES=\n", $this->envContents());
    }

    public function test_a_database_that_refuses_the_write_ends_the_section_with_an_error(): void
    {
        Setting::flushCache();
        $this->bootInMemoryDatabase();
        $this->promptsAnswerKeys();
        DB::unprepared("CREATE TRIGGER refuse_insert BEFORE INSERT ON settings BEGIN SELECT RAISE(ABORT, 'settings are read-only'); END");
        $this->keys([...self::row(1), self::typed('4G'), Key::ENTER, Key::ENTER]);

        $section = new PrewarmSection();

        $this->assertSame(1, $section->run(false));
        $this->assertScreenShows('settings are read-only');
        $this->assertSame([], $section->receipt());
        Setting::flushCache();
    }
}
