<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\DockerfileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A final stage on a smaller base, for platforms where building and running
 * want different images.
 *
 * .NET is why this exists. The single-stage image was the SDK plus the entire
 * cloned source tree plus the published output, and on Jellyfin that cost
 * 294s of layer export against 63s of actual compile -- the export, not the
 * build, was the dominant cost of every .NET deploy.
 */
class MultiStageCommandTest extends TestCase
{
    private function dotnet(array $overrides = []): string
    {
        // $overrides first: PHP's + keeps the left operand for a key present
        // in both, so the defaults have to be the right-hand side.
        return DockerfileBuilder::generate($overrides + [
            'runtime' => 'command',
            'image' => 'mcr.microsoft.com/dotnet/sdk:10.0',
            'runtime_image' => 'mcr.microsoft.com/dotnet/aspnet:10.0',
            'output_directory' => 'out',
            'install_command' => 'dotnet publish App/App.csproj -c Release -o out --nologo',
            'port_hint' => 8080,
        ], []);
    }

    public function test_it_builds_on_the_sdk_and_runs_on_the_runtime(): void
    {
        $dockerfile = $this->dotnet();

        $this->assertStringContainsString('FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build', $dockerfile);
        $this->assertStringContainsString('FROM mcr.microsoft.com/dotnet/aspnet:10.0', $dockerfile);
    }

    /** The audit targets file is written by the same RUN, with its MSBuild property reference intact. */
    public function test_the_engines_publish_command_reaches_the_run_line_verbatim(): void
    {
        $command = \App\Lib\Deploy\Platform\Runtime\DotnetRuntime::buildCommand('');

        $this->assertStringContainsString('RUN ' . $command . "\n", $this->dotnet(['install_command' => $command]));
    }

    /** The whole point: the source tree does not reach the final image. */
    public function test_only_the_published_output_is_carried_over(): void
    {
        $dockerfile = $this->dotnet();

        $this->assertStringContainsString('COPY --from=build /app/out ./out', $dockerfile);
        $this->assertSame(
            1,
            substr_count($dockerfile, 'COPY . .'),
            'the source is copied into the build stage only'
        );
    }

    public function test_the_entrypoint_and_port_land_on_the_final_stage(): void
    {
        $dockerfile = $this->dotnet();
        $final = substr($dockerfile, (int) strrpos($dockerfile, 'FROM '));

        $this->assertStringContainsString('EXPOSE 8080', $final);
        $this->assertStringContainsString('ENTRYPOINT', $final);
        // The app reads its own configuration at runtime, not at build time.
        $this->assertStringContainsString('ENV PORT=8080', $final);
    }

    /** Both halves are required; neither is guessed. */
    public function test_a_runtime_image_without_an_output_directory_stays_single_stage(): void
    {
        $dockerfile = $this->dotnet(['output_directory' => '']);

        $this->assertSame(1, substr_count($dockerfile, 'FROM '));
    }

    public function test_an_output_directory_without_a_runtime_image_stays_single_stage(): void
    {
        $dockerfile = $this->dotnet(['runtime_image' => '']);

        $this->assertSame(1, substr_count($dockerfile, 'FROM '));
    }

    /**
     * Go, Rust, Java and Python declare no runtime image and must render
     * exactly as they did before this existed.
     */
    public function test_a_platform_that_declares_no_runtime_image_is_unchanged(): void
    {
        $dockerfile = DockerfileBuilder::generate([
            'runtime' => 'command',
            'image' => 'golang:1.26-alpine',
            'install_command' => 'go mod download',
            'build_command' => 'go build -o app ./...',
            'port_hint' => 8080,
        ], []);

        $this->assertSame(1, substr_count($dockerfile, 'FROM '));
        $this->assertStringNotContainsString('--from=build', $dockerfile);
        $this->assertStringContainsString('COPY . .', $dockerfile);
    }
}
