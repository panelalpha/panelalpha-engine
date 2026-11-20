<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\QueueSection;
use App\Console\Wizard\Sections\SitesDbSection;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Support\SitesDbCaches;
use Laravel\Prompts\Key;
use RuntimeException;
use Tests\Support\LocalRoot;
use Tests\Support\NoProcessHost;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure sites-db` and `queue`: two numbers each section writes to `.env` and applies. */
class SitesDbAndQueueSectionsTest extends TestCase
{
    use WizardScreen;

    private const INNODB = 0;

    private const KEY_BUFFER = 1;

    private const CHECK = 3;

    /** @var list<string> */
    public array $asked = [];

    public ?string $mariadb = "33554432\t8388608\t8388608";

    public bool $recreates = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startWizardScreen();
        HostMemoryProbe::fake(new HostMemory(3809));
    }

    protected function tearDown(): void
    {
        HostMemoryProbe::fake(null);
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_a_running_sites_db_is_recreated_with_a_new_size_once_confirmed(): void
    {
        $this->keys([...self::row(self::INNODB), self::typed('4', '32M'), self::typed('64m', '4'), Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = $this->sitesDb();

        $this->assertSame(0, $section->run(false));
        $this->assertScreenShows('Configured Running');
        $this->assertScreenShows('InnoDB buffer pool 32M 32M');
        $this->assertScreenShows("A size like 32M or 1G. The engine's default is 32M; MariaDB's own is 128M.");
        $this->assertScreenShows('sites-db restarts to apply it');
        $this->assertScreenShows('InnoDB buffer pool set to 64M. sites-db was recreated with it.');
        $this->assertSame(['InnoDB buffer pool set to 64M.'], $section->receipt());
        $this->assertStringContainsString('SITES_DB_INNODB_BUFFER_POOL_SIZE=64M', $this->envContents());
        $this->assertContains('compose.engineUpWithoutDependencies', $this->asked);
    }

    public function test_a_stopped_sites_db_is_only_written(): void
    {
        $this->mariadb = null;
        $this->keys([...self::row(self::CHECK), ...self::row(self::KEY_BUFFER), self::typed('16M', '8M'), Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = $this->sitesDb();
        $section->run(false);

        $this->assertScreenShows('sites-db is not running; changes apply when it starts.');
        $this->assertScreenShows('MyISAM key buffer set to 16M. It applies when sites-db next starts.');
        $this->assertStringContainsString('SITES_DB_KEY_BUFFER_SIZE=16M', $this->envContents());
        $this->assertNotContains('compose.engineUpWithoutDependencies', $this->asked);
    }

    public function test_keeping_the_size_declining_the_restart_or_a_failed_apply_change_nothing(): void
    {
        $this->recreates = false;
        $this->keys([
            ...self::row(self::INNODB), Key::ENTER,
            ...self::row(self::INNODB), self::typed('64M', '32M'), 'n', Key::ENTER,
            ...self::row(self::INNODB), self::typed('64M', '32M'), Key::ENTER, Key::ENTER,
            Key::CTRL_E, Key::ENTER,
        ]);

        $section = $this->sitesDb();
        $section->run(false);

        $this->assertScreenShows('Could not apply it: sites-db did not come back');
        $this->assertSame([], $section->receipt());
        $this->assertSame(1, count(array_keys($this->asked, 'compose.engineUpWithoutDependencies', true)));
    }

    public function test_the_queue_shows_what_is_configured_and_takes_a_whole_number_in_range(): void
    {
        file_put_contents($this->envPath(), "APP_KEY=base64:dGVzdA==\nQUEUE_WORKERS=3\n");
        $this->keys([
            ...self::row(1),
            Key::ENTER, self::typed('two', '3'), self::typed('99', 'two'), self::typed('4', '99'), Key::ENTER,
            Key::ENTER, Key::ENTER,
            ...self::row(2),
        ]);

        $section = new QueueSection();

        $this->assertSame(0, $section->run(true));
        $this->assertScreenShows('Configured: 3');
        $this->assertScreenShows('Running: (unknown -- s6 did not answer)');
        $this->assertScreenShows('A number from 1 to 32.');
        $this->assertScreenShows('Enter a whole number.');
        $this->assertScreenShows('Enter a number from 1 to 32.');
        $this->assertScreenShows('Dry run: QUEUE_WORKERS would become 4.');
        $this->assertStringContainsString('QUEUE_WORKERS=3', $this->envContents());
        $this->assertSame([], $section->receipt());
    }

    public function test_a_queue_count_that_cannot_be_written_says_why(): void
    {
        unlink($this->envPath());
        $this->keys([Key::ENTER, self::typed('7', '2'), Key::ENTER, ...self::row(2)]);

        $section = new QueueSection();
        $section->run(false);

        $this->assertScreenShows('No .env file at '.$this->envPath().'.');
        $this->assertSame([], $section->receipt());
    }

    private function sitesDb(): SitesDbSection
    {
        $test = $this;

        return new SitesDbSection(new SitesDbCaches(new class ($test, $this->wizardDir) extends NoProcessHost {
            public function __construct(private SitesDbAndQueueSectionsTest $test, private string $dir) {}

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $action = (string) LocalRoot::action((array) $cmd);
                $this->test->asked[] = $action;

                return match ($action) {
                    'compose.sitesDbCacheSizes' => $this->test->mariadb ?? throw new RuntimeException('service "sites-db" is not running'),
                    'compose.engineUpWithoutDependencies' => $this->test->recreates ? '' : throw new RuntimeException("sites-db did not come back\n"),
                    default => throw new RuntimeException("unexpected {$action}"),
                };
            }
        }));
    }
}
