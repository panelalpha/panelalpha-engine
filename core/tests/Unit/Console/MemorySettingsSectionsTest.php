<?php

namespace Tests\Unit\Console;

use App\Console\Wizard\Sections\CsfUiSection;
use App\Console\Wizard\Sections\SitesDbSection;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Support\CsfUi;
use App\Support\SitesDbCaches;
use App\System;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** `pae configure sites-db` and `pae configure csf-ui`, against a fake engine dir and host. */
class MemorySettingsSectionsTest extends TestCase
{
    private string $dir;

    private bool $fallback = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pae-mem-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', "COMPOSE_PROFILES=full\n#SITES_DB_INNODB_BUFFER_POOL_SIZE=32M\nCSF_UI_PASSWORD=s3cret\n");

        // An artisan command run earlier in the suite switches every prompt to its fallback.
        $this->fallback = (new ReflectionProperty(Prompt::class, 'shouldFallback'))->getValue();
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, $this->fallback);
        HostMemoryProbe::fake(null);
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** A System rooted at $this->dir that records commands; sites-db answers $mariadb, or is down when null. */
    private function system(?string $mariadb = "33554432\t8388608\t8388608", string $csfConf = "UI = \"0\"\n"): System
    {
        return new class ($this->dir, $mariadb, $csfConf) extends System {
            /** @var list<string> */
            public array $ran = [];

            public function __construct(private string $dir, private ?string $mariadb, private string $csfConf)
            {
            }

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = implode(' ', (array) $cmd);
                $this->ran[] = $line;
                if (str_contains($line, ' exec -T sites-db ')) {
                    return $this->mariadb ?? throw new RuntimeException('service "sites-db" is not running');
                }
                if (str_contains($line, 'cat /etc/csf/csf.conf')) {
                    return $this->csfConf;
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                return $this->exec($cmd, $env);
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = implode(' ', (array) $cmd);
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };
    }

    public function test_sizes_default_to_the_engines_and_read_back_live(): void
    {
        $caches = new SitesDbCaches($this->system());

        $this->assertSame(['SITES_DB_INNODB_BUFFER_POOL_SIZE' => '32M', 'SITES_DB_KEY_BUFFER_SIZE' => '8M',
            'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE' => '8M'], $caches->configured());
        $this->assertSame(['SITES_DB_INNODB_BUFFER_POOL_SIZE' => 32, 'SITES_DB_KEY_BUFFER_SIZE' => 8,
            'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE' => 8], $caches->running());
        $this->assertNull((new SitesDbCaches($this->system(null)))->running());
    }

    public function test_a_size_needs_a_unit_a_floor_and_to_fit_the_host(): void
    {
        $innodb = 'SITES_DB_INNODB_BUFFER_POOL_SIZE';
        $this->assertNull(SitesDbCaches::badSize($innodb, '64M', 3809));
        $this->assertNull(SitesDbCaches::badSize($innodb, '1g', 3809));
        $this->assertNotNull(SitesDbCaches::badSize($innodb, '64', 3809), 'bytes, not megabytes');
        $this->assertNotNull(SitesDbCaches::badSize($innodb, '4M', 3809), 'below the floor');
        $this->assertNotNull(SitesDbCaches::badSize($innodb, '4G', 3809), 'more than the host');
        $this->assertNull(SitesDbCaches::badSize('SITES_DB_KEY_BUFFER_SIZE', '1M', 3809));
    }

    public function test_applying_writes_env_in_place_of_the_placeholder_and_recreates_sites_db(): void
    {
        $system = $this->system();
        $result = (new SitesDbCaches($system))->apply(['SITES_DB_INNODB_BUFFER_POOL_SIZE' => '64m']);

        $this->assertSame(['changed' => ['SITES_DB_INNODB_BUFFER_POOL_SIZE'], 'recreated' => true], $result);
        $this->assertSame("COMPOSE_PROFILES=full\nSITES_DB_INNODB_BUFFER_POOL_SIZE=64M\nCSF_UI_PASSWORD=s3cret\n", file_get_contents($this->dir . '/.env'));
        $this->assertContains("sudo docker compose --project-directory {$this->dir} up -d --no-deps sites-db", $system->ran);
    }

    /** A stopped sites-db is not started just to change its caches. */
    public function test_a_stopped_sites_db_is_written_but_not_started(): void
    {
        $system = $this->system(null);
        $result = (new SitesDbCaches($system))->apply(['SITES_DB_KEY_BUFFER_SIZE' => '16M']);

        $this->assertTrue($result['changed'] !== [] && !$result['recreated']);
        $this->assertEmpty(array_filter($system->ran, fn ($c) => str_contains($c, ' up -d ')));
    }

    public function test_the_section_in_dry_run_writes_nothing(): void
    {
        HostMemoryProbe::fake(new HostMemory(3809));
        $before = file_get_contents($this->dir . '/.env');
        Prompt::fake([Key::BACKSPACE, Key::BACKSPACE, Key::BACKSPACE, '6', '4', 'M', Key::ENTER, Key::ENTER]);

        (new ReflectionMethod(SitesDbSection::class, 'setSize'))
            ->invoke(new SitesDbSection(new SitesDbCaches($this->system())), 'SITES_DB_INNODB_BUFFER_POOL_SIZE', '32M', true);

        Prompt::assertOutputContains('Dry run: SITES_DB_INNODB_BUFFER_POOL_SIZE would become 64M');
        $this->assertSame($before, file_get_contents($this->dir . '/.env'));
    }

    public function test_turning_the_csf_ui_on_writes_env_and_csf_conf_and_restarts_lfd(): void
    {
        $system = $this->system();
        $ui = new CsfUi($system);

        $this->assertFalse($ui->enabled());
        $this->assertFalse($ui->configured());
        $ui->set(true);

        $this->assertTrue($ui->configured());
        $this->assertStringContainsString("CSF_UI=1\n", file_get_contents($this->dir . '/.env'));
        $this->assertContains('sed -i s/^UI = ".*/UI = "1"/ /etc/csf/csf.conf', $system->ran);
        $this->assertContains('systemctl restart lfd', $system->ran);
        $this->assertSame('s3cret', $ui->password());
    }

    public function test_the_csf_section_prints_the_login_when_turned_on(): void
    {
        Prompt::fake(['y', Key::ENTER, Key::ENTER]);

        (new ReflectionMethod(CsfUiSection::class, 'toggle'))
            ->invoke(new CsfUiSection(new CsfUi($this->system())), true, false);

        Prompt::assertOutputContains('CSF UI turned on.');
        Prompt::assertOutputContains('user panelalpha, password s3cret');
    }
}
