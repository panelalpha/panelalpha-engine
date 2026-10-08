<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * The `.panelalpha/` directory: the app config as files rather than as one
 * document.
 */
class AppConfigDirectoryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-app config-dir-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function test_a_missing_or_empty_directory_is_no_app_config(): void
    {
        $this->assertNull(AppConfigDirectory::read($this->source(), $this->dir . '/nope'));
        $this->assertNull(AppConfigDirectory::read($this->source(), $this->dir));
    }

    public function test_each_file_lands_where_the_engine_reads_it(): void
    {
        $this->write(AppConfigDirectory::PRECHECK, "df -Pk .\n");
        $this->write(AppConfigDirectory::PREPARE, "./generate-config\n");
        $this->write(AppConfigDirectory::ENTRYPOINT, "#!/bin/bash\nexec supervisord\n");
        $this->write(AppConfigDirectory::APP_SCRIPT, "#!/bin/bash\necho '[\"info\"]'\n");
        $this->write(AppConfigDirectory::COMPOSE, "services: {app: {image: demo}}\n");
        $this->write('files/docker/cli.mjs', "#!/usr/bin/env node\n");
        $this->write('files/wp-content/mu-plugins/pa.php', "<?php\n");

        $appConfig = AppConfigDirectory::read($this->source(), $this->dir);

        $this->assertNotNull($appConfig);
        $this->assertStringContainsString('df -Pk', (string) $appConfig->preCheckCommands());
        $this->assertStringContainsString('generate-config', (string) $appConfig->setupCommands());
        $this->assertStringContainsString('supervisord', (string) $appConfig->entrypoint());
        $this->assertStringContainsString('info', (string) $appConfig->appScript());
        $this->assertStringContainsString('image: demo', (string) $appConfig->compose());
        $this->assertSame(AppConfig::COMPOSE_REPLACE, $appConfig->composeMode());
        $this->assertSame(
            ['docker/cli.mjs', 'wp-content/mu-plugins/pa.php'],
            array_column($appConfig->files(), 'path')
        );
    }

    /** The filename is the mode, as Compose itself means those two names. */
    public function test_the_override_name_layers_instead_of_replacing(): void
    {
        $this->write(AppConfigDirectory::COMPOSE_OVERRIDE, "services: {app: {ports: ['8080:80']}}\n");

        $appConfig = AppConfigDirectory::read($this->source(), $this->dir);

        $this->assertNotNull($appConfig);
        $this->assertSame(AppConfig::COMPOSE_OVERRIDE, $appConfig->composeMode());
    }

    public function test_shipping_both_compose_files_is_refused(): void
    {
        $this->write(AppConfigDirectory::COMPOSE, "services: {}\n");
        $this->write(AppConfigDirectory::COMPOSE_OVERRIDE, "services: {}\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('one replaces');
        AppConfigDirectory::read($this->source(), $this->dir);
    }

    public function test_the_config_carries_what_is_structured(): void
    {
        $this->write(AppConfigDirectory::CONFIG, "extends: matomo\nenv:\n  APP_ENV: production\n");

        $appConfig = AppConfigDirectory::read($this->source(), $this->dir);

        $this->assertNotNull($appConfig);
        // `extends` rides along in the manifest array: it is the address of
        // the base recipe, which the source-recipe layer resolves, and a
        // config with its own `id` relies on it surviving this far.
        $this->assertSame(['extends' => 'matomo', 'id' => 'matomo'], $appConfig->manifest());
        $this->assertSame(['APP_ENV' => 'production'], $appConfig->env());
    }

    /** A file beside the config is the more specific statement of the two. */
    public function test_a_file_wins_over_the_same_thing_written_inline(): void
    {
        $this->write(AppConfigDirectory::CONFIG, "prepare: |\n  echo inline\n");
        $this->write(AppConfigDirectory::PREPARE, "echo from-the-file\n");

        $appConfig = AppConfigDirectory::read($this->source(), $this->dir);

        $this->assertNotNull($appConfig);
        $this->assertStringContainsString('from-the-file', (string) $appConfig->setupCommands());
    }

    /**
     * Every directory the engine ships parses, says something, and carries a
     * `panelalpha.yaml` — the one file that is not optional there, because it
     * is where the description and any `platform:` live.
     */
    public function test_the_shipped_directories_load(): void
    {
        $source = $this->source();
        $directories = SourceRecipes::directories();

        $this->assertNotEmpty($directories);
        foreach ($directories as $slug => $dir) {
            $this->assertFileExists($dir . '/' . AppConfigDirectory::CONFIG, "{$slug} has no config");
            $appConfig = AppConfigDirectory::read($source, $dir, true);
            $this->assertNotNull($appConfig, "{$slug} holds no app config");
        }
    }

    /** In the engine's own tree the config is required, not optional. */
    public function test_a_directory_without_a_config_is_refused(): void
    {
        $this->write(AppConfigDirectory::PREPARE, "echo hello\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage(AppConfigDirectory::CONFIG . ' is required');
        AppConfigDirectory::read($this->source(), $this->dir, true);
    }

    /** A repository's own directory is taken as it comes. */
    public function test_a_repository_may_ship_a_directory_with_no_config(): void
    {
        $this->write(AppConfigDirectory::ENTRYPOINT, "#!/bin/bash\nexec supervisord\n");

        $appConfig = AppConfigDirectory::read($this->source(), $this->dir);

        $this->assertNotNull($appConfig);
        $this->assertStringContainsString('supervisord', (string) $appConfig->entrypoint());
    }

    private function source(): LocalAppConfigSource
    {
        return new LocalAppConfigSource();
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
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
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
