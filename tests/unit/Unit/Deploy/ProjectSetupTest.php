<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\ProjectSetup;
use PHPUnit\Framework\TestCase;

class ProjectSetupTest extends TestCase
{
    public function test_composer_setup_script_wins(): void
    {
        $composer = json_encode([
            'scripts' => ['setup' => ['@php artisan db:seed']],
        ]);
        $package = json_encode([
            'scripts' => ['setup' => 'node scripts/seed.js'],
        ]);

        $this->assertSame(
            'composer run-script --no-interaction setup',
            ProjectSetup::command($composer, $package)
        );
    }

    public function test_package_json_setup_uses_detected_package_manager(): void
    {
        $package = json_encode(['scripts' => ['setup' => 'prisma migrate deploy']]);

        $this->assertSame(
            'npm run setup',
            ProjectSetup::command(null, $package, ['package.json' => true])
        );
        $this->assertSame(
            'pnpm run setup',
            ProjectSetup::command(null, $package, ['package.json' => true, 'pnpm-lock.yaml' => true])
        );
    }

    public function test_bin_setup_fallback(): void
    {
        $this->assertSame('sh bin/setup', ProjectSetup::command(null, null, ['bin/setup' => true]));
    }

    public function test_no_declared_setup(): void
    {
        $this->assertNull(ProjectSetup::command('{"require":{"php":"^8.3"}}', '{"scripts":{"build":"vite"}}'));
    }

    public function test_first_boot_wraps_once_and_relaxes_production_env(): void
    {
        $shell = ProjectSetup::wrapStartCommand('npm start', 'npm run setup');

        $this->assertStringContainsString('.panelalpha-bootstrapped', $shell);
        $this->assertStringContainsString('APP_ENV=local', $shell);
        $this->assertStringContainsString('RAILS_ENV=development', $shell);
        $this->assertStringContainsString('NODE_ENV=development', $shell);
        $this->assertStringContainsString('npm run setup || true', $shell);
        $this->assertStringContainsString('&& npm start', $shell);
    }

    public function test_no_setup_leaves_start_command_unchanged(): void
    {
        $this->assertSame('npm start', ProjectSetup::wrapStartCommand('npm start', null));
        $this->assertSame('npm start', ProjectSetup::wrapStartCommand('npm start', '  '));
    }

    /**
     * Every PHP deploy resolves vendor/ on the host, whether the manifest
     * declares the command or falls back to PhpHostBuild::DEFAULT_INSTALL --
     * so a PHP platform supersedes the project's script either way.
     */
    public function test_php_runtime_supersedes_the_projects_setup_script(): void
    {
        $this->assertTrue(ProjectSetup::isSupersededByPlatform('php', true));
        $this->assertTrue(ProjectSetup::isSupersededByPlatform('php', false));
    }

    public function test_declared_build_commands_supersede_the_projects_setup_script(): void
    {
        $this->assertTrue(ProjectSetup::isSupersededByPlatform('node', true));
        $this->assertTrue(ProjectSetup::isSupersededByPlatform('nginx', true));
    }

    /**
     * A platform with no build stage has not resolved anything, so the
     * project's own script stays the only bootstrap there is.
     */
    public function test_a_platform_with_no_build_stage_still_runs_it(): void
    {
        $this->assertFalse(ProjectSetup::isSupersededByPlatform('node', false));
        $this->assertFalse(ProjectSetup::isSupersededByPlatform('command', false));
        $this->assertFalse(ProjectSetup::isSupersededByPlatform(null, false));
    }
}
