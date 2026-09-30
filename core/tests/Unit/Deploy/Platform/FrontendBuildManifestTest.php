<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `frontend_build:` lets a PHP recipe turn the host frontend pass off (Crater
 * commits public/build) or name the command it runs (grocy's `yarn install`),
 * instead of editing package.json from a prepare hook (engine#433).
 */
class FrontendBuildManifestTest extends TestCase
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

    public function test_absent_keeps_the_build_script_rule(): void
    {
        $this->assertNull($this->make()->frontendBuild);
        $this->assertNull($this->make(['frontend_build' => null])->frontendBuild);
    }

    public function test_false_and_a_command_are_read(): void
    {
        $this->assertFalse($this->make(['frontend_build' => false])->frontendBuild);
        $this->assertSame('yarn install', $this->make(['frontend_build' => ' yarn install '])->frontendBuild);
    }

    /** @return array<string, array{mixed}> */
    public static function invalid(): array
    {
        return [
            'true' => [true],
            'empty' => [''],
            'blank' => ['   '],
            'list' => [['yarn install']],
        ];
    }

    #[DataProvider('invalid')]
    public function test_anything_else_is_refused(mixed $value): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/frontend_build must be false or a non-empty command/');
        $this->make(['frontend_build' => $value]);
    }

    public function test_another_runtime_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches("/only supported for runtime 'php'/");
        $this->make(['runtime' => 'node', 'frontend_build' => false]);
    }

    public function test_the_decision_carries_it_to_the_php_strategy(): void
    {
        $context = ProjectContext::make(sys_get_temp_dir(), []);

        $this->assertFalse($this->make(['frontend_build' => false])->describe($context)['frontend_build']);
        $this->assertNull($this->make()->describe($context)['frontend_build']);
    }

    public function test_a_source_recipe_extending_laravel_can_declare_it(): void
    {
        $manifest = SourceRecipes::fromAppConfig(
            AppConfig::fromYaml("extends: laravel\nfrontend_build: false\n"),
            'panelalpha.yaml'
        );

        $this->assertNotNull($manifest);
        $this->assertFalse($manifest->frontendBuild);
    }
}
