<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformValues;
use App\Lib\Deploy\Platform\ProjectContext;
use Tests\TestCase;

/**
 * The values a manifest cannot state as literals, filled in from the project.
 *
 * A manifest says `commands_from: {build: rust.build}` because the real
 * command depends on what is in the checkout - which binary Cargo will
 * produce, which Python entry point exists, whether this is a Gradle or a
 * Maven build. Getting one wrong produces a container that builds and then
 * cannot start, which reads as a broken deploy rather than a bad recipe.
 */
class PlatformValuesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-values-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function manifest(array $raw): PlatformManifest
    {
        return PlatformManifest::fromArray($raw + [
            'id' => 'test',
            'label' => 'Test',
            'priority' => 500,
            'detect' => ['file' => 'nothing-here'],
        ], 'test.yaml');
    }

    private function context(): ProjectContext
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return ProjectContext::make($this->dir, $files);
    }

    public function test_a_manifest_with_no_resolvers_resolves_nothing(): void
    {
        $resolved = PlatformValues::resolvedCommands($this->manifest([]), $this->context());

        $this->assertSame([], $resolved);
    }

    public function test_a_resolver_produces_a_command_for_this_project(): void
    {
        file_put_contents($this->dir . '/requirements.txt', "django==5.0\n");

        $resolved = PlatformValues::resolvedCommands(
            $this->manifest([
                'commands' => [['id' => 'install', 'stages' => ['build'], 'run' => 'placeholder']],
                'commands_from' => ['install' => 'python.install'],
            ]),
            $this->context()
        );

        $this->assertArrayHasKey('install', $resolved);
        $this->assertStringContainsString('requirements.txt', $resolved['install']);
    }

    public function test_the_two_java_build_systems_get_different_start_commands(): void
    {
        // Maven and Gradle put the jar in different places under different
        // names. One start command for both would run nothing.
        $maven = PlatformValues::resolvedCommands(
            $this->manifest([
                'commands' => [['id' => 'serve', 'stages' => ['start'], 'run' => 'x', 'serve' => true]],
                'commands_from' => ['serve' => 'java.start'],
            ]),
            $this->context()
        );
        $gradle = PlatformValues::resolvedCommands(
            $this->manifest([
                'commands' => [['id' => 'serve', 'stages' => ['start'], 'run' => 'x', 'serve' => true]],
                'commands_from' => ['serve' => 'java.start-gradle'],
            ]),
            $this->context()
        );

        $this->assertNotSame($maven['serve'], $gradle['serve']);
    }

    public function test_a_resolver_nobody_wrote_is_a_manifest_error(): void
    {
        // Caught when the manifest is used rather than producing an empty
        // command that fails at build time with nothing to point at.
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("unknown command resolver 'ruby.magic' for 'build'");

        PlatformValues::resolvedCommands(
            $this->manifest([
                'commands' => [['id' => 'build', 'stages' => ['build'], 'run' => 'x']],
                'commands_from' => ['build' => 'ruby.magic'],
            ]),
            $this->context()
        );
    }

    public function test_resolved_build_commands_are_split_by_the_layer_they_belong_to(): void
    {
        // The dependency layer is copied before the source so BuildKit can
        // cache it; folding both into one command would rebuild everything
        // on every commit.
        file_put_contents($this->dir . '/requirements.txt', "django==5.0\n");

        $decision = PlatformValues::apply(
            $this->manifest([
                'runtime' => 'command',
                'commands' => [
                    ['id' => 'install', 'stages' => ['build'], 'run' => 'x', 'role' => 'dependencies'],
                    ['id' => 'collect', 'stages' => ['build'], 'run' => 'python manage.py collectstatic --noinput', 'role' => 'assets'],
                ],
                'commands_from' => ['install' => 'python.install'],
            ]),
            $this->context(),
            []
        );

        $this->assertStringContainsString('requirements.txt', $decision['install_command']);
        $this->assertSame('python manage.py collectstatic --noinput', $decision['build_command']);
    }

    public function test_a_manifests_own_image_is_used_as_written(): void
    {
        $decision = PlatformValues::apply(
            $this->manifest(['image' => 'ghcr.io/acme/base:1']),
            $this->context(),
            []
        );

        $this->assertSame('ghcr.io/acme/base:1', $decision['image']);
    }

    public function test_a_manifest_that_requires_nothing_names_no_image(): void
    {
        // The writer then picks one. A wrong guess here would be baked in.
        $decision = PlatformValues::apply($this->manifest([]), $this->context(), []);

        $this->assertNull($decision['image']);
    }

    public function test_a_manifests_declared_output_directory_is_kept(): void
    {
        $decision = PlatformValues::apply(
            $this->manifest(['output_directory' => 'public/build']),
            $this->context(),
            []
        );

        $this->assertSame('public/build', $decision['output_directory']);
    }

    public function test_an_angular_output_directory_is_read_from_the_project(): void
    {
        // Angular states it in its own config under a project name nobody can
        // predict, so no manifest can write it down.
        file_put_contents($this->dir . '/angular.json', (string) json_encode([
            'projects' => ['shop' => ['architect' => ['build' => ['options' => ['outputPath' => 'dist/shop']]]]],
        ]));

        $decision = PlatformValues::apply(
            $this->manifest(['output_from' => 'angular']),
            $this->context(),
            []
        );

        $this->assertSame('dist/shop', $decision['output_directory']);
    }

    public function test_a_workspace_output_directory_is_prefixed_with_the_workspace(): void
    {
        // `.next` in a monorepo is `apps/web/.next`; the unprefixed path is
        // an empty directory that serves nothing.
        $decision = PlatformValues::apply(
            $this->manifest(['output_from' => 'next-workspace', 'output_directory' => '.next']),
            $this->context(),
            ['workspace_relative' => 'apps/web']
        );

        $this->assertSame('apps/web/.next', $decision['output_directory']);
    }

    public function test_a_single_package_project_is_not_prefixed(): void
    {
        $decision = PlatformValues::apply(
            $this->manifest(['output_from' => 'next-workspace', 'output_directory' => '.next']),
            $this->context(),
            ['workspace_relative' => '']
        );

        $this->assertSame('.next', $decision['output_directory']);
    }

    public function test_an_output_resolver_nobody_wrote_is_a_manifest_error(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("unknown output resolver 'svelte'");

        PlatformValues::apply($this->manifest(['output_from' => 'svelte']), $this->context(), []);
    }
}
