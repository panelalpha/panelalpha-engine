<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `hand_over:` names what a host compile leaves when it made what another recipe serves, and that recipe. */
class HandOverManifestTest extends TestCase
{
    private function make(array $overrides = []): PlatformManifest
    {
        return PlatformManifest::fromArray(array_merge([
            'id' => 'demo-export',
            'label' => 'Demo',
            'priority' => 100,
            'runtime' => 'nginx',
            'output_directory' => 'out',
            'detect' => ['file' => 'package.json'],
            'commands' => [],
        ], $overrides));
    }

    public function test_absent_is_null(): void
    {
        $this->assertNull($this->make()->handOver);
    }

    public function test_the_marker_and_recipe_are_read(): void
    {
        $handOver = $this->make(['hand_over' => ['marker' => '.next/BUILD_ID', 'recipe' => 'nextjs']])->handOver;

        $this->assertSame('.next/BUILD_ID', $handOver?->marker);
        $this->assertSame('nextjs', $handOver?->recipe);
    }

    public function test_the_marker_sits_beside_the_output_directory(): void
    {
        $handOver = $this->make(['hand_over' => ['marker' => '.next/BUILD_ID', 'recipe' => 'nextjs']])->handOver;

        $this->assertSame('.next/BUILD_ID', $handOver?->markerBeside('out'));
        $this->assertSame('apps/web/.next/BUILD_ID', $handOver?->markerBeside('apps/web/out'));
        $this->assertSame('apps/web/.next/BUILD_ID', $handOver?->markerBeside('/apps/web/out/'));
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalid(): array
    {
        return [
            'a string' => ['nextjs', 'hand_over must be an object with marker and recipe'],
            'a list' => [['.next/BUILD_ID', 'nextjs'], 'hand_over must be an object with marker and recipe'],
            'an unknown key' => [['marker' => 'x', 'recipe' => 'nextjs', 'port' => 3000], 'hand_over must be an object with marker and recipe'],
            'no marker' => [['recipe' => 'nextjs'], 'hand_over.marker must be a relative file path'],
            'an absolute marker' => [['marker' => '/etc/passwd', 'recipe' => 'nextjs'], 'hand_over.marker must be a relative file path'],
            'a marker leaving the checkout' => [['marker' => '../.next/BUILD_ID', 'recipe' => 'nextjs'], 'hand_over.marker must be a relative file path'],
            'no recipe' => [['marker' => '.next/BUILD_ID'], 'hand_over.recipe must be a recipe id'],
            'a recipe that is no id' => [['marker' => '.next/BUILD_ID', 'recipe' => 'Next JS'], 'hand_over.recipe must be a recipe id'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_anything_else_is_refused(mixed $value, string $message): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage($message);
        $this->make(['hand_over' => $value]);
    }

    public function test_a_node_recipe_takes_it(): void
    {
        $handOver = $this->make(['runtime' => 'node', 'hand_over' => ['marker' => 'out/index.html', 'recipe' => 'nextjs-export']])->handOver;

        $this->assertSame('out/index.html', $handOver?->markerBeside('.next'));
        $this->assertSame('apps/web/out/index.html', $handOver?->markerBeside('apps/web/.next'));
    }

    public function test_a_recipe_that_does_not_compile_on_the_host_does_not(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("hand_over is only supported for runtime 'nginx' or 'node'");
        $this->make(['runtime' => 'php', 'hand_over' => ['marker' => '.next/BUILD_ID', 'recipe' => 'nextjs']]);
    }
}
