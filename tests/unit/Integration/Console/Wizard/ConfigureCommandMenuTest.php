<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Commands\ConfigureCommand;
use App\Console\Prompts\Screen;
use App\Console\Wizard\Wizard;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure` on a terminal: the area menu, an area named on the command line, and the receipt left at the end. */
class ConfigureCommandMenuTest extends TestCase
{
    use InMemoryDatabase;
    use WizardScreen;

    private const TELEMETRY_STOP_SENDING = [Key::ENTER, 'y', Key::ENTER, Key::ENTER, Key::CTRL_A, Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN, Key::ENTER];

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->startWizardScreen();
        Http::fake(['*' => Http::response(['ok' => true])]);
        config([
            'telemetry.enabled' => true,
            'telemetry.tier' => DeployReport::TIER_LOG,
            'telemetry.source_bundle.mode' => SourceBundlePolicy::MODE_OFF,
            'telemetry.bug_reports.enabled' => true,
            'telemetry.spool_dir' => $this->wizardDir.'/outbox',
            'monitoring.url' => '',
        ]);
        Telemetry::resetCache();
        Setting::flushCache();
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Screen::class, 'terminal'))->setValue(null, null);
        Telemetry::resetCache();
        Setting::flushCache();
        $this->stopWizardScreen();
        parent::tearDown();
    }

    /** @param array<string, mixed> $arguments */
    private function configure(array $arguments = []): int
    {
        $command = new class extends ConfigureCommand
        {
            protected function canAsk(): bool
            {
                return true;
            }

            protected function configurePrompts(InputInterface $input)
            {
            }
        };
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput($arguments), $this->output);
    }

    private function menuRow(string $key): int
    {
        return (int) array_search($key, [...array_keys(Wizard::sections()), 'quit'], true);
    }

    public function test_an_area_that_does_not_exist_is_refused_with_the_ones_that_do(): void
    {
        $this->keys([]);

        $this->assertSame(1, $this->configure(['section' => 'nonsense']));

        $printed = $this->output->fetch();
        $this->assertStringContainsString('There is nothing called "nonsense" to configure. There is: ', $printed);
        $this->assertStringContainsString(implode(', ', array_keys(Wizard::sections())), $printed);
    }

    public function test_leaving_the_menu_at_once_changes_nothing(): void
    {
        $this->keys(self::row($this->menuRow('quit')));

        $this->assertSame(0, $this->configure());

        $this->assertScreenShows('What would you like to configure?');
        $this->assertScreenShows('Nothing more — leave the wizard');
        $this->assertScreenShows('Nothing was changed.');
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_an_area_named_on_the_command_line_runs_without_the_menu_and_leaves_its_receipt(): void
    {
        $this->keys(self::TELEMETRY_STOP_SENDING);

        $this->assertSame(0, $this->configure(['section' => ' Telemetry ']));

        $this->assertSame('0', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        $this->assertScreenDoesNotShow('What would you like to configure?');
        $this->assertScreenShows('Done.');
        $this->assertScreenShows('Telemetry sending is now OFF.');
    }

    public function test_the_menu_comes_back_after_an_area_and_keeps_its_receipt_to_the_end(): void
    {
        $this->keys([
            ...self::row($this->menuRow('telemetry')),
            ...self::TELEMETRY_STOP_SENDING,
            ...self::row($this->menuRow('quit')),
        ]);

        $this->assertSame(0, $this->configure());

        $this->assertSame('0', Setting::get(NotificationPreferences::SETTING_TELEMETRY_ENABLED));
        $this->assertScreenShows('Done.');
        $this->assertScreenShows('Telemetry sending is now OFF.');
        $this->assertScreenDoesNotShow('Nothing was changed.');
    }
}
