<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\StageScript;
use App\Lib\Deploy\Platform\ManifestException;
use PHPUnit\Framework\TestCase;

/**
 * `database: mysql` — the manifest key that says an application needs a
 * database and cannot ask for one itself.
 *
 * Laravel reads its .env and a project shipping a compose file brings its
 * own; a PHP CMS has neither, so before this it deployed with
 * `DB_CONNECTION=sqlite` pointing at a file the image never created, and its
 * installer stopped at the database step with nothing to type in.
 */
class AppDatabaseManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-db-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/core', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['core/Version.php', 'matomo.php', 'piwik.php', 'composer.json', 'artisan', 'index.php'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir . '/core');
        @rmdir($this->dir);
    }

    private function write(string $name, string $contents = ''): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    private function matomoTree(): void
    {
        $this->write('composer.json', '{"require":{"php":">=7.2.5"}}');
        $this->write('matomo.php');
        $this->write('piwik.php');
        $this->write('core/Version.php');
    }

    public function test_matomo_is_recognised_and_asks_for_a_database(): void
    {
        $this->matomoTree();

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('matomo', $decision['platform']);
        $this->assertSame('Matomo', $decision['label']);
        // The PHP strategy builds and serves it; only the manifest differs.
        $this->assertSame('php', $decision['strategy']);
        $this->assertSame('mysql', $decision['database']);
    }

    /** Matomo must outrank plain PHP, or its composer.json settles it first. */
    public function test_matomo_outranks_php(): void
    {
        $this->assertGreaterThan(
            PlatformRegistry::find('php')->priority,
            PlatformRegistry::find('matomo')->priority
        );
        $this->assertLessThan(
            PlatformRegistry::find('laravel')->priority,
            PlatformRegistry::find('matomo')->priority
        );
    }

    public function test_a_php_project_that_is_not_matomo_asks_for_nothing(): void
    {
        $this->write('composer.json', '{"require":{"php":"^8.3"}}');
        $this->write('index.php', '<?php');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('php', $decision['platform']);
        $this->assertNull($decision['database']);
    }

    /** Laravel points itself at a database; the engine must not second-guess it. */
    public function test_laravel_asks_for_nothing(): void
    {
        $this->write('composer.json', '{"require":{"php":"^8.3"}}');
        $this->write('artisan');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('laravel', $decision['platform']);
        $this->assertNull($decision['database']);
    }

    /**
     * Matomo's own installer reads MATOMO_DATABASE_* — the variables its
     * Docker image uses — so the account's credentials arrive prefilled
     * rather than having to be looked up and typed.
     */
    public function test_matomo_hands_its_installer_the_provisioned_credentials(): void
    {
        $script = StageScript::render(PlatformRegistry::find('matomo'));

        foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD', 'DBNAME'] as $field) {
            $this->assertStringContainsString("MATOMO_DATABASE_{$field}", $script);
        }
        // Exported into the entrypoint's own shell, which Apache inherits —
        // a subshell would leave the server with none of them.
        $this->assertStringContainsString('export MATOMO_DATABASE_HOST', $script);
        $this->assertLessThan(
            strpos($script, 'exec apache2-foreground') ?: PHP_INT_MAX,
            strpos($script, 'export MATOMO_DATABASE_HOST') ?: PHP_INT_MAX
        );
    }

    /**
     * A rebuilt container has an installed database and a config file that
     * went with the old container. Without this it would show the wizard over
     * a site full of data.
     */
    public function test_matomo_points_a_rebuilt_container_back_at_its_own_tables(): void
    {
        $script = StageScript::render(PlatformRegistry::find('matomo'));

        $this->assertStringContainsString('/app/config/config.ini.php', $script);
        // No chown. The staged commands run as the hosting account
        // (`pa_as_app`), which owns the bind-mounted tree and cannot give a
        // file away to another user -- the attempt failed the command, and an
        // unmarked command failing aborts the boot. www-data does not serve
        // this container either: Apache runs as the account.
        $this->assertStringNotContainsString('chown', $script);
        // Never over a config Matomo wrote itself, and never on a first
        // deploy — there is nothing installed yet, and the installer is what
        // should run.
        $this->assertStringContainsString('if ! grep -q "^\\[database\\]"', $script);
        $this->assertStringContainsString("pa_step upgrade 'restore-config'", $script);
        $this->assertStringNotContainsString("pa_step install 'restore-config'", $script);
    }

    public function test_an_unknown_database_is_refused(): void
    {
        $this->expectException(ManifestException::class);

        PlatformManifest::fromArray([
            'id' => 'thing',
            'label' => 'Thing',
            'priority' => 10,
            'database' => 'postgres',
            'detect' => ['file' => 'thing.json'],
        ]);
    }
}
