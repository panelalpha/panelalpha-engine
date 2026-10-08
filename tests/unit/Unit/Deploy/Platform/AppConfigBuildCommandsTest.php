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
 * An app config's `stage: build` commands never ran.
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

    /**
     * my-mind commits its built site at the root and carries a package.json
     * of dev tooling. The static strategy serves the checkout, so the
     * package.json must not turn it into an `npm install` that then looks for
     * dist/index.html.
     */
    public function test_a_static_recipe_does_not_become_a_js_build(): void
    {
        $dir = sys_get_temp_dir() . '/pa-static-js-' . bin2hex(random_bytes(6));
        mkdir($dir . '/' . AppConfigDirectory::DIRNAME, 0777, true);
        file_put_contents($dir . '/index.html', '<!doctype html><title>My Mind</title>');
        file_put_contents($dir . '/package.json', '{"devDependencies":{"rollup":"4.0.0"},"scripts":{"build":"rollup -c"}}');
        file_put_contents($dir . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG, "extends: static\n");

        try {
            $decision = DetectProjectStrategy::detect($dir, 'https://github.com/acme/site');
        } finally {
            @unlink($dir . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG);
            @rmdir($dir . '/' . AppConfigDirectory::DIRNAME);
            @unlink($dir . '/index.html');
            @unlink($dir . '/package.json');
            @rmdir($dir);
        }

        $this->assertSame('static', $decision['strategy']);
        $this->assertSame('', (string) ($decision['install_command'] ?? ''));
        $this->assertSame('', (string) ($decision['build_command'] ?? ''));
    }

    /**
     * Cronicle's recipe restated the express install line verbatim, and the
     * host build ran `{{js.install}}` as a command: `sh: 1: {{js.install}}: not found`.
     */
    public function test_a_restated_js_placeholder_is_resolved_like_the_manifests(): void
    {
        $dir = sys_get_temp_dir() . '/pa-js-recipe-' . bin2hex(random_bytes(6));
        mkdir($dir . '/' . AppConfigDirectory::DIRNAME, 0777, true);
        file_put_contents($dir . '/package.json', (string) json_encode(['name' => 'cronicle', 'dependencies' => ['express' => '4.0.0']]));
        file_put_contents($dir . '/package-lock.json', '{"lockfileVersion": 3}');
        file_put_contents($dir . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG, <<<'YAML'
            extends: express
            commands:
              - id: npm-install
                stage: build
                role: dependencies
                run: '{{js.install}}'
              - id: cronicle-build
                stage: build
                run: 'node bin/build.js dist'
              - id: extra-build
                stage: build
                run: '{{js.build:node bin/build.js extra}}'
            YAML);

        try {
            $decision = DetectProjectStrategy::detect($dir, 'https://github.com/acme/cronicle');
        } finally {
            @unlink($dir . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG);
            @rmdir($dir . '/' . AppConfigDirectory::DIRNAME);
            @unlink($dir . '/package.json');
            @unlink($dir . '/package-lock.json');
            @rmdir($dir);
        }

        $this->assertStringContainsString('npm ci', $decision['install_command']);
        $this->assertStringNotContainsString('{{', $decision['install_command'] . $decision['build_command']);
        $this->assertSame('node bin/build.js dist && node bin/build.js extra', $decision['build_command']);
    }

    /**
     * Oriveo: the Next app is an npm workspace under web/ and the repository
     * root has no package.json. `extends: nextjs` + `app_root: web` handed
     * `{{js.install}}` to the host build verbatim.
     */
    public function test_an_app_root_resolves_the_js_toolchain_and_workspace_there(): void
    {
        $dir = sys_get_temp_dir() . '/pa-app-root-' . bin2hex(random_bytes(6));
        $files = [
            'web/package.json' => (string) json_encode([
                'workspaces' => ['apps/*', 'packages/*'],
                'scripts' => ['build:app' => 'npm run build --workspace @oriveo/app'],
            ]),
            'web/package-lock.json' => '{"lockfileVersion": 3}',
            'web/apps/app/package.json' => (string) json_encode([
                'name' => '@oriveo/app',
                'dependencies' => ['next' => '16.0.0'],
                'scripts' => ['build' => 'next build', 'start' => 'next start --hostname 127.0.0.1 --port 3001'],
            ]),
            AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG => "extends: nextjs\napp_root: web\n",
        ];
        foreach ($files as $path => $contents) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, $contents);
        }

        try {
            $decision = DetectProjectStrategy::detect($dir, 'https://github.com/acme/app');
        } finally {
            foreach (array_keys($files) as $path) {
                @unlink($dir . '/' . $path);
            }
            foreach (['web/apps/app', 'web/apps', 'web', AppConfigDirectory::DIRNAME, ''] as $sub) {
                @rmdir(rtrim($dir . '/' . $sub, '/'));
            }
        }

        $this->assertSame('web', $decision['app_root']);
        $this->assertSame('HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', $decision['install_command']);
        $this->assertSame('npm run build:app', $decision['build_command']);
        $this->assertStringContainsString('next start -H 0.0.0.0 -p 3000', $decision['start_command']);
        $this->assertSame('apps/app/.next', $decision['output_directory']);
    }
}
