<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * What a specific project says about hosting itself.
 *
 * These pin the capabilities, not the format — the point of the class is that
 * a `panelalpha.yaml` can replace the markdown behind it without any caller
 * noticing.
 */
class AppConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/app config-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/project', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    private function page(): string
    {
        return <<<'MD'
        # Demo

        - `panelalpha-before-clone-validation.sh`
        ```bash
        df -Pk . | awk 'NR==2{exit $4 < 2000000}'
        ```

        - `panelalpha-after-clone.sh`
        ```bash
        ./docker/panelalpha-cli.mjs init
        ```

        - `docker-compose.yml`
        ```yaml
        services:
          app:
            image: demo:latest
        ```

        - `docker/panelalpha-cli.mjs`
        ```js
        #!/usr/bin/env node
        console.log('hi')
        ```

        - `panelalpha-app.sh`
        ```bash
        #!/bin/bash
        echo '["info"]'
        ```
        MD;
    }

    private function writePage(string $contents): void
    {
        file_put_contents($this->tmpDir . '/project/panelalpha.md', $contents);
    }

    public function test_it_exposes_every_capability_an_app_config_can_carry(): void
    {
        $appConfig = AppConfig::fromContent($this->page());
        $this->assertNotNull($appConfig);

        $this->assertStringContainsString('df -Pk', (string) $appConfig->preCheckCommands());
        $this->assertStringContainsString('panelalpha-cli.mjs init', (string) $appConfig->setupCommands());
        $this->assertStringContainsString('image: demo:latest', (string) $appConfig->compose());
        $this->assertStringContainsString('#!/bin/bash', (string) $appConfig->appScript());

        $paths = array_column($appConfig->files(), 'path');
        $this->assertContains('docker/panelalpha-cli.mjs', $paths);
    }

    /**
     * The special block names are consumed by the pipeline; everything else is
     * a file. A page whose app script leaked into the file list would write
     * panelalpha-app.sh into the project twice.
     */
    public function test_special_blocks_are_not_also_copied_as_files(): void
    {
        $paths = array_column((AppConfig::fromContent($this->page()))->files(), 'path');

        $this->assertNotContains('panelalpha-app.sh', $paths);
        $this->assertNotContains('panelalpha-after-clone.sh', $paths);
        $this->assertNotContains('docker-compose.yml', $paths);
    }

    public function test_an_empty_descriptor_is_no_app_config_at_all(): void
    {
        $this->assertNull(AppConfig::fromContent(''));
        $this->assertNull(AppConfig::fromContent("   \n  "));
    }

    /**
     * An upstream that has learned to describe its own hosting knows more
     * than a page written about it from outside.
     */
    public function test_a_descriptor_in_the_repository_wins_over_the_engines_page(): void
    {
        $candidates = AppConfigLocator::candidates($this->tmpDir . '/project', 'https://github.com/acme/app.git');
        $paths = array_column($candidates, 'path');

        // Location outranks format: everything the repository could ship
        // comes before anything the engine wrote, and the directory leads
        // each group.
        $this->assertCount(6, $candidates);
        $this->assertSame($this->tmpDir . '/project/.panelalpha', $paths[0]);
        $this->assertSame($this->tmpDir . '/project/panelalpha.md', $paths[1]);
        $this->assertSame($this->tmpDir . '/project/panelalpha.yaml', $paths[2]);
        $this->assertStringContainsString('/sources/github.com/acme/app', $paths[3]);
        $this->assertStringContainsString('/acme/app/panelalpha.md', $paths[4]);
        $this->assertStringContainsString('/acme/app/panelalpha.yaml', $paths[5]);
        $this->assertSame(
            ['repository', 'repository', 'repository', 'engine', 'engine', 'engine'],
            array_column($candidates, 'origin')
        );
    }

    public function test_a_project_with_no_git_url_only_looks_in_the_repository(): void
    {
        $this->assertCount(3, AppConfigLocator::candidates($this->tmpDir . '/project'));
    }

    public function test_load_reads_the_first_candidate_that_exists(): void
    {
        $this->assertNull(AppConfig::load(new LocalAppConfigSource(), $this->tmpDir . '/project'));

        $this->writePage($this->page());
        $appConfig = AppConfig::load(new LocalAppConfigSource(), $this->tmpDir . '/project');

        $this->assertNotNull($appConfig);
        $this->assertStringContainsString('image: demo:latest', (string) $appConfig->compose());
    }

    /**
     * The distinction that decides whether detection stands down: an app config
     * that only ships an app script is annotating the deploy, not defining it.
     */
    public function test_a_page_that_only_adds_tools_writes_no_compose(): void
    {
        $appOnly = <<<'MD'
        - `panelalpha-app.sh`
        ```bash
        echo '["info"]'
        ```
        MD;

        // Nothing to write into the checkout, so the bootstrap leaves the
        // project's own files alone and detection reads them unchanged.
        $this->assertNull((AppConfig::fromContent($appOnly))->compose());
        $this->assertNotNull((AppConfig::fromContent($this->page()))->compose());
    }
}
