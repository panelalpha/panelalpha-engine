<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Detect\HtmlSite;
use App\Lib\Deploy\Detect\PhpSources;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Env\EnvExampleCopies;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Probes\NextWorkspaceProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Telemetry\SourceBundle;
use PHPUnit\Framework\TestCase;

/**
 * The account owns its checkout and may chmod 700 a directory in
 * it (Phorge's conf/local). The engine walks the tree as another user, so every
 * walker must skip what it cannot read rather than fail the deploy on
 * `scandir(...): Failed to open directory: Permission denied`.
 *
 * Warnings throw, as they do under Laravel's error handler in production.
 */
class UnreadableCheckoutDirectoryTest extends TestCase
{
    private string $dir = '';

    /** @var list<string> */
    private array $locked = [];

    private ?int $reporting = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-unreadable-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->reporting !== null) {
            restore_error_handler();
            error_reporting($this->reporting);
        }
        foreach ($this->locked as $path) {
            @chmod($path, 0755);
        }
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function test_entries_lists_an_unreadable_directory_as_empty(): void
    {
        $this->lock('conf/local');

        $this->assertSame([], ProjectContext::entries($this->dir . '/conf/local'));
        $this->assertContains('local', ProjectContext::entries($this->dir . '/conf'));
    }

    public function test_detection_survives_a_locked_directory_in_a_php_tree_without_composer(): void
    {
        // Phorge's shape: no composer.json, no PHP at the root.
        $this->write('src/lib/a.php', '<?php echo 1;');
        $this->lock('a-conf/local');

        $this->assertTrue(PhpSources::present($this->dir));
        $this->assertSame('php', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_detection_survives_a_locked_directory_in_a_django_tree(): void
    {
        $this->write('requirements.txt', "django\n");
        $this->write('manage.py', "import django\nDJANGO_SETTINGS_MODULE = 'mysite.settings'\n");
        $this->write('mysite/__init__.py', '');
        $this->write('mysite/wsgi.py', '');
        $this->lock('conf/local');

        $this->assertSame('', PythonRuntime::djangoManageDir($this->dir));
        $this->assertSame('django', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_go_main_package_is_found_past_a_locked_directory(): void
    {
        $this->write('go.mod', "module example.com/x\n\ngo 1.22\n");
        $this->write('cmd/server/main.go', "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tfmt.Println(1)\n}\n");
        $this->lock('conf/local');

        $this->assertSame('./cmd/server', GoRuntime::mainPackage($this->dir));
    }

    public function test_html_site_and_env_examples_skip_a_locked_directory(): void
    {
        $this->write('home.html', '<h1>hi</h1>');
        $this->write('app/.env.example', "A=1\n");
        $this->lock('app/local');

        $this->assertSame('home.html', HtmlSite::entry($this->dir));
        $this->assertSame(['app/.env'], array_column(EnvExampleCopies::for($this->dir), 'relative'));
    }

    public function test_next_workspace_probe_skips_a_locked_workspace_group(): void
    {
        $this->write('apps/web/package.json', '{"dependencies":{"next":"14"}}');
        $this->lock('packages');

        $this->assertSame('apps/web', NextWorkspaceProbe::findWorkspaceApp($this->dir)['relative'] ?? null);
    }

    public function test_app_config_listing_skips_a_locked_directory(): void
    {
        $this->write('files/a.txt', 'a');
        $this->lock('files/private');

        $this->assertSame(['a.txt'], (new LocalAppConfigSource())->listFiles($this->dir . '/files'));
    }

    public function test_telemetry_bundle_is_still_written_past_a_locked_directory(): void
    {
        $this->write('index.php', '<?php echo 1;');
        $this->write('conf/local/local.json', '{"secret":1}');
        $this->lock('conf/local');
        $zip = $this->dir . '.zip';

        try {
            $result = SourceBundle::create($this->dir, $zip, 1_000_000, 100, 100_000);
            $this->assertTrue($result['available'], json_encode($result));
        } finally {
            @unlink($zip);
        }
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /** chmod 000 as the owner; root reads through it, so the case cannot be made. */
    private function lock(string $relative): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        chmod($path, 0);
        $this->locked[] = $path;
        if (@scandir($path) !== false) {
            $this->markTestSkipped('running as a user that can read a mode-000 directory');
        }
        // What Laravel's HandleExceptions does. Here, not in setUp(): PHPUnit
        // installs its own handler after setUp.
        if ($this->reporting === null) {
            $this->reporting = error_reporting(-1);
            set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
                if ((error_reporting() & $level) === 0) {
                    return false;
                }
                throw new \ErrorException($message, 0, $level, $file, $line);
            });
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
