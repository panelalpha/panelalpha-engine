<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogFilter;
use PHPUnit\Framework\TestCase;

class DeployLogFilterTest extends TestCase
{
    public function test_hides_docker_load_ids_and_gitconfig_noise(): void
    {
        $this->assertTrue(DeployLogFilter::shouldSkip('ae74b2c44f40'));
        $this->assertTrue(DeployLogFilter::shouldSkip(
            "error: could not lock config file /home/acmeshop/.gitconfig: Permission denied"
        ));
    }

    public function test_hides_bundler_asset_listings_whatever_the_output_dir(): void
    {
        // Matched on the "<path>.<ext>  <size>" shape, so it is not tied to
        // Laravel Vite's public/build, Nuxt's .output or Encore's build/.
        $this->assertTrue(DeployLogFilter::shouldSkip('public/build/assets/app-B8VMFSdP.js      521.15 kB'));
        $this->assertTrue(DeployLogFilter::shouldSkip('.output/public/_nuxt/entry.CxK1p.css   12.4 kB'));
        $this->assertTrue(DeployLogFilter::shouldSkip('dist/assets/index-9f1c.js   1.2 MB'));
        $this->assertTrue(DeployLogFilter::shouldSkip('  - Downloading symfony/console (v8.0.8)'));
    }

    public function test_keeps_run_steps_and_failures(): void
    {
        $this->assertFalse(DeployLogFilter::shouldSkip('#16 [app 6/9] RUN composer install --no-dev'));
        $this->assertFalse(DeployLogFilter::shouldSkip('Deploy finished successfully'));
        $this->assertFalse(DeployLogFilter::shouldSkip('Loaded base image mysql:8.4 from host cache'));
    }
}
