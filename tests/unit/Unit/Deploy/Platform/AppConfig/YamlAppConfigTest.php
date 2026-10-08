<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformStage;
use PHPUnit\Framework\TestCase;

/**
 * `panelalpha.yaml` — the same vocabulary as a platform manifest, scoped to
 * one project.
 */
class YamlAppConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/yaml-app config-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/project', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    private function yaml(): string
    {
        return <<<'YAML'
        description: Demo app

        requires:
          node:
            role: build
            optional: true

        env:
          APP_ENV: production

        precheck: |
          df -Pk . | awk 'NR==2{exit $4 < 2000000}'

        commands:
          - id: seed-config
            stage: prepare
            run: ./bin/generate-config

        files:
          docker/cli.mjs: |
            #!/usr/bin/env node

        app:
          script: |
            #!/bin/bash
            echo '["info"]'

        compose:
          mode: override
          content: |
            services:
              app:
                image: demo:latest
        YAML;
    }

    public function test_it_reads_every_field(): void
    {
        $appConfig = AppConfig::fromYaml($this->yaml());
        $this->assertNotNull($appConfig);

        $this->assertStringContainsString('df -Pk', (string) $appConfig->preCheckCommands());
        $this->assertStringContainsString('image: demo:latest', (string) $appConfig->compose());
        $this->assertSame(AppConfig::COMPOSE_OVERRIDE, $appConfig->composeMode());
        $this->assertStringContainsString('["info"]', (string) $appConfig->appScript());
        $this->assertSame(['docker/cli.mjs'], array_column($appConfig->files(), 'path'));
        $this->assertSame(['node'], array_keys($appConfig->requires()));
        $this->assertSame(['APP_ENV' => 'production'], $appConfig->env());
    }

    /**
     * The gain over markdown: commands are named, staged and guarded
     * individually rather than being one undifferentiated script.
     */
    public function test_commands_carry_stages_like_a_manifests(): void
    {
        $appConfig = AppConfig::fromYaml($this->yaml());

        $prepare = $appConfig->commands(PlatformStage::PREPARE);
        $this->assertCount(1, $prepare);
        $this->assertSame('seed-config', $prepare[0]->id);
        $this->assertSame([], $appConfig->commands(PlatformStage::START));
    }

    public function test_compose_defaults_to_replacing(): void
    {
        $appConfig = AppConfig::fromYaml("compose: |\n  services: {}\n");

        $this->assertSame(AppConfig::COMPOSE_REPLACE, $appConfig->composeMode());
    }

    /**
     * A snippet is written under the project directory. A path that climbs
     * out of it would write anywhere the account can reach.
     */
    public function test_a_file_path_cannot_escape_the_project(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/not a path inside the project/');
        AppConfig::fromYaml("files:\n  ../../etc/cron.d/evil: 'x'\n");
    }

    public function test_an_absolute_file_path_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        AppConfig::fromYaml("files:\n  /etc/passwd: 'x'\n");
    }

    /**
     * Regression: `ltrim($path, './')` strips any run of those two
     * characters, so it turned `../../etc/cron.d/x` into `etc/cron.d/x` —
     * normalising the traversal away instead of refusing it.
     *
     * @dataProvider escapingPaths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('escapingPaths')]
    public function test_no_path_shape_escapes_the_project(string $path): void
    {
        $this->expectException(ManifestException::class);
        AppConfig::fromYaml("files:\n  \"{$path}\": 'x'\n");
    }

    /** @return array<string, array{0: string}> */
    public static function escapingPaths(): array
    {
        return [
            'parent'           => ['../secrets'],
            'deep parent'      => ['../../etc/cron.d/evil'],
            'absolute'         => ['/etc/passwd'],
            'traversal inside' => ['a/../../b'],
            'bare dotdot'      => ['..'],
            'trailing dotdot'  => ['a/..'],
        ];
    }

    public function test_a_leading_dot_slash_is_stripped_but_the_path_is_kept(): void
    {
        $appConfig = AppConfig::fromYaml("files:\n  ./docker/cli.mjs: 'x'\n");

        $this->assertSame('docker/cli.mjs', $appConfig->files()[0]['path']);
    }

    public function test_a_misspelled_key_is_rejected_rather_than_ignored(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/unknown key\(s\) platfrom/');
        AppConfig::fromYaml("platfrom: laravel\n");
    }

    public function test_malformed_yaml_is_reported_as_such(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/Invalid YAML/');
        AppConfig::fromYaml("image: [unclosed\n");
    }

    /** A build that runs `git describe --tags` needs the history the default clone leaves out. */
    public function test_git_history_full_asks_for_the_whole_history(): void
    {
        $this->assertTrue(AppConfig::fromYaml("git:\n  history: full\n")->fullGitHistory());
        $this->assertFalse(AppConfig::fromYaml("git:\n  history: shallow\n")?->fullGitHistory() ?? false);
        $this->assertFalse(AppConfig::fromYaml("description: Demo\nprepare: 'true'\n")->fullGitHistory());
    }

    public function test_an_unknown_git_history_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches("/'git' must be/");
        AppConfig::fromYaml("git:\n  history: deep\n");
    }

    /** An app that installs itself at first start needs longer than the deploy probe. */
    public function test_health_start_period_is_read(): void
    {
        $this->assertSame(900, AppConfig::fromYaml("health:\n  start_period: 900\n")?->startPeriod());
        $this->assertSame(0, AppConfig::fromYaml("health:\n  start_period: 0\n")?->startPeriod());
        $this->assertNull(AppConfig::fromYaml("description: Demo\nprepare: 'true'\n")?->startPeriod());
    }

    public function test_a_start_period_out_of_range_is_refused(): void
    {
        foreach (["health:\n  start_period: 1801\n", "health:\n  start_period: '60'\n", "health:\n  start: 60\n", "health: 60\n"] as $yaml) {
            try {
                AppConfig::fromYaml($yaml);
                $this->fail("accepted: {$yaml}");
            } catch (ManifestException $e) {
                $this->assertStringContainsString("'health' must be", $e->getMessage());
            }
        }
    }

    public function test_an_unknown_compose_mode_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        AppConfig::fromYaml("compose:\n  mode: merge\n  content: 'services: {}'\n");
    }

    /**
     * The markdown page is the one the engine looks for first.
     *
     * And it takes the whole app config with it: the YAML beside it is not read,
     * so its `image` never reaches the pipeline. That is the price of
     * the ordering, and it is asserted here rather than left to be discovered
     * on a deploy.
     */
    public function test_markdown_wins_when_both_formats_are_present(): void
    {
        $dir = $this->tmpDir . '/project';
        file_put_contents($dir . '/panelalpha.md', "- `panelalpha-app.sh`\n```bash\necho md\n```\n");
        file_put_contents($dir . '/panelalpha.yaml', "app: |\n  echo yaml\n");

        $appConfig = AppConfig::load(new LocalAppConfigSource(), $dir);

        $this->assertStringContainsString('echo md', (string) $appConfig?->appScript());
        $this->assertSame(
            [],
            $appConfig?->commands(),
            'the yaml beside it is not consulted'
        );
    }

    /**
     * The YAML form is read when the repository ships no markdown page.
     */
    public function test_yaml_is_read_when_it_is_the_only_one(): void
    {
        $dir = $this->tmpDir . '/project';
        file_put_contents($dir . '/panelalpha.yaml', "app: |\n  echo yaml\n");

        $appConfig = AppConfig::load(new LocalAppConfigSource(), $dir);

        $this->assertNotNull($appConfig);
        $this->assertStringContainsString('echo yaml', (string) $appConfig?->appScript());
    }

    public function test_markdown_is_still_read_when_it_is_the_only_one(): void
    {
        $dir = $this->tmpDir . '/project';
        file_put_contents($dir . '/panelalpha.md', "- `panelalpha-app.sh`\n```bash\necho md\n```\n");

        $appConfig = AppConfig::load(new LocalAppConfigSource(), $dir);

        $this->assertStringContainsString('echo md', (string) $appConfig?->appScript());
    }

}
