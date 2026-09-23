<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Strategy\AppConfigBootstrap;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * ADR-0001 #06 — an app config's compose file is written under the engine's
 * reserved names (never the client's own compose filename), and a stale one
 * from a mode the app config no longer uses is removed.
 */
class AppConfigBootstrapTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/pa-appconfig-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
        $this->projectDir = $this->homeRoot.'/alice/project';
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_replace_mode_writes_the_app_configs_reserved_compose_file(): void
    {
        $appConfig = AppConfig::fromYaml("compose:\n  content: |\n    services:\n      app:\n        image: from-app-config\n");
        $this->assertNotNull($appConfig);

        $this->writeCompose($appConfig);

        $this->assertStringContainsString(
            'from-app-config',
            (string) file_get_contents($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE)
        );
        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
    }

    public function test_replace_mode_never_writes_under_the_clients_own_compose_name(): void
    {
        $appConfig = AppConfig::fromYaml("compose:\n  content: |\n    services: {}\n");
        $this->assertNotNull($appConfig);

        $this->writeCompose($appConfig);

        $this->assertFileDoesNotExist($this->projectDir.'/docker-compose.yml');
    }

    public function test_override_mode_writes_the_engine_override_file(): void
    {
        $appConfig = AppConfig::fromYaml(
            "compose:\n  mode: override\n  content: |\n    services:\n      app:\n        volumes: ['./x:/x']\n"
        );
        $this->assertNotNull($appConfig);

        $this->writeCompose($appConfig);

        $this->assertStringContainsString(
            './x:/x',
            (string) file_get_contents($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE)
        );
        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);
    }

    public function test_switching_from_replace_to_override_removes_the_stale_replace_file(): void
    {
        $replace = AppConfig::fromYaml("compose:\n  content: |\n    services: {}\n");
        $this->assertNotNull($replace);
        $this->writeCompose($replace);
        $this->assertFileExists($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);

        $override = AppConfig::fromYaml("compose:\n  mode: override\n  content: |\n    services: {}\n");
        $this->assertNotNull($override);
        $this->writeCompose($override);

        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);
        $this->assertFileExists($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
    }

    public function test_switching_from_override_to_replace_removes_the_stale_override_file(): void
    {
        $override = AppConfig::fromYaml("compose:\n  mode: override\n  content: |\n    services: {}\n");
        $this->assertNotNull($override);
        $this->writeCompose($override);
        $this->assertFileExists($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);

        $replace = AppConfig::fromYaml("compose:\n  content: |\n    services: {}\n");
        $this->assertNotNull($replace);
        $this->writeCompose($replace);

        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
        $this->assertFileExists($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);
    }

    /**
     * An app config that stops shipping a compose file at all must not leave
     * either reserved file behind — they survive `clean -fd` (excluded), so a
     * stale one would otherwise outlive the app config that wrote it.
     */
    public function test_an_app_config_that_stops_shipping_compose_removes_both_reserved_files(): void
    {
        file_put_contents($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE, "services: {}\n");
        file_put_contents($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE, "services: {}\n");

        $appConfig = AppConfig::fromYaml("precheck: echo hi\n");
        $this->assertNotNull($appConfig);
        $this->assertNull($appConfig->compose());

        $this->writeCompose($appConfig);

        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);
        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
    }

    /**
     * Found live (ticket 08, scenario C): a `.panelalpha/` left holding only a
     * description loads as no app config at all, and that path skipped the
     * removal — the stale file kept outranking the client's own compose file.
     */
    public function test_an_app_config_that_is_gone_entirely_removes_both_reserved_files(): void
    {
        file_put_contents($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE, "services: {}\n");
        file_put_contents($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE, "services: {}\n");

        (new AppConfigBootstrap($this->dind()))->run(null, $this->projectDir, null);

        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::APP_CONFIG_COMPOSE);
        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE);
    }

    public function test_entrypoint_is_written_under_the_reserved_project_override_path(): void
    {
        $appConfig = AppConfig::fromYaml("entrypoint: |\n  #!/bin/sh\n  exec php-fpm\n");
        $this->assertNotNull($appConfig);

        $bootstrap = new AppConfigBootstrap($this->dind());
        (new ReflectionMethod($bootstrap, 'writeEntrypoint'))
            ->invoke($bootstrap, $appConfig, $this->projectDir, null);

        $this->assertStringContainsString(
            'exec php-fpm',
            (string) file_get_contents($this->projectDir.'/'.EntrypointWriter::PROJECT_OVERRIDE)
        );
    }

    private function writeCompose(AppConfig $appConfig): void
    {
        $bootstrap = new AppConfigBootstrap($this->dind());
        (new ReflectionMethod($bootstrap, 'writeCompose'))
            ->invoke($bootstrap, $appConfig, $this->projectDir, null);
    }

    private function dind(): Dind
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ]);

        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->homeRoot), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
