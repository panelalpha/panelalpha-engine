<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Platform\StageResolver;
use PHPUnit\Framework\TestCase;

/**
 * engine#171: an app config's `stage: build` commands never ran.
 *
 * AppConfig strips `commands` from the manifest it describes, and the host
 * build reads only the decision's `install_command` / `build_command`, which
 * are projections of the manifest's own build stage. SPIP's recipe restates
 * `composer-install` without `--no-plugins` because its layout plugin places
 * ecrire/; the deploy ran the stock command instead.
 */
class AppConfigBuildCommandsTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = sys_get_temp_dir() . '/pa-build-cmds-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/' . AppConfigDirectory::DIRNAME, 0777, true);
        file_put_contents(
            $this->project . '/composer.json',
            (string) json_encode(['require' => ['php' => '>=8.2']])
        );
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->project . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG);
        @rmdir($this->project . '/' . AppConfigDirectory::DIRNAME);
        @unlink($this->project . '/composer.json');
        @rmdir($this->project);
        SourceRecipes::flush();
        parent::tearDown();
    }

    private function config(string $yaml): void
    {
        file_put_contents($this->project . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG, $yaml);
    }

    public function test_a_restated_build_command_replaces_the_manifests(): void
    {
        $this->config(<<<'YAML'
            extends: php
            commands:
              - id: composer-install
                stage: build
                role: dependencies
                run: 'composer install --no-dev --no-interaction --no-scripts --optimize-autoloader'
              - id: publish-assets
                stage: build
                run: 'php bin/publish'
            YAML);

        $decision = DetectProjectStrategy::detect($this->project, 'https://github.com/acme/app');

        $this->assertSame(
            'composer install --no-dev --no-interaction --no-scripts --optimize-autoloader',
            $decision['install_command']
        );
        $this->assertStringContainsString('post-autoload-dump', $decision['build_command']);
        $this->assertStringEndsWith('php bin/publish', $decision['build_command']);
    }

    /** A recipe with no build command of its own gets exactly what it got before. */
    public function test_an_app_config_without_build_commands_changes_nothing(): void
    {
        $this->config(<<<'YAML'
            extends: php
            commands:
              - id: setup
                stage: start
                run: 'sh panelalpha/setup.sh'
            YAML);

        $withConfig = DetectProjectStrategy::detect($this->project, 'https://github.com/acme/app');
        $php = PlatformRegistry::find('php');
        $this->assertNotNull($php);

        $this->assertSame(
            'composer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader',
            $withConfig['install_command']
        );
        $this->assertSame('{ composer run-script --no-interaction post-autoload-dump; } || true', $withConfig['build_command']);
    }

    /** The one resolver every stage goes through: same id means "instead of", in any stage. */
    public function test_the_stage_resolver_replaces_by_id(): void
    {
        $php = PlatformRegistry::find('php');
        $this->assertNotNull($php);
        $appConfig = AppConfig::fromYaml(<<<'YAML'
            commands:
              - id: composer-install
                stage: build
                role: dependencies
                run: 'composer install --no-dev'
            YAML);

        $commands = StageResolver::commandsFor(PlatformStage::BUILD, $php, $appConfig);
        $ids = array_map(static fn ($c) => $c->id, $commands);

        $this->assertSame(['post-autoload-dump', 'composer-install'], $ids);
        $this->assertSame('composer install --no-dev', $commands[1]->run);
    }

    /** The real recipe that needed it. */
    public function test_spip_gets_the_install_it_declares(): void
    {
        $spip = sys_get_temp_dir() . '/pa-spip-' . bin2hex(random_bytes(6));
        mkdir($spip);
        file_put_contents($spip . '/composer.json', (string) json_encode(['require' => ['php' => '>=8.2']]));
        file_put_contents($spip . '/spip.php', "<?php\n");

        try {
            $decision = DetectProjectStrategy::detect($spip, 'https://git.spip.net/spip/spip');
        } finally {
            @unlink($spip . '/composer.json');
            @unlink($spip . '/spip.php');
            @rmdir($spip);
        }

        $this->assertStringNotContainsString('--no-plugins', $decision['install_command']);
        $this->assertStringStartsWith('composer install --no-dev', $decision['install_command']);
    }
}
