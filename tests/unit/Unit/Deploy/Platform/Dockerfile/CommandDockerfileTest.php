<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\BuildRecipe;
use App\Lib\Deploy\Platform\Dockerfile\CommandDockerfile;
use Tests\TestCase;

/**
 * The generic recipe: an official language image, the source, whatever the
 * manifest said to install and build, and the staged entrypoint.
 *
 * Go, Rust, Java and Python all deploy through this one writer - the
 * difference between them is entirely in the manifest, which is the point of
 * having manifests at all.
 */
class CommandDockerfileTest extends TestCase
{
    /**
     * @param array<string, mixed> $decision
     */
    private function render(array $decision): string
    {
        return (new CommandDockerfile(new BuildRecipe($decision)))->render();
    }

    public function test_the_manifests_image_and_commands_are_used(): void
    {
        $dockerfile = $this->render([
            'image' => 'golang:1.22',
            'install_command' => 'go mod download',
            'build_command' => 'go build -o /app/server ./cmd/server',
        ]);

        $this->assertStringContainsString('FROM golang:1.22', $dockerfile);
        $this->assertStringContainsString('RUN go mod download', $dockerfile);
        $this->assertStringContainsString('RUN go build -o /app/server ./cmd/server', $dockerfile);
    }

    public function test_a_step_the_manifest_did_not_name_is_not_emitted(): void
    {
        // `RUN` with nothing after it is a build failure on the first line.
        $dockerfile = $this->render(['image' => 'python:3.12', 'build_command' => '']);

        $this->assertStringNotContainsString("RUN \n", $dockerfile);
        $this->assertStringNotContainsString('RUN' . PHP_EOL, $dockerfile);
    }

    public function test_the_server_is_told_to_listen_where_the_proxy_looks(): void
    {
        // 0.0.0.0 rather than localhost: a server bound to loopback inside a
        // container answers nothing from outside it.
        $dockerfile = $this->render(['image' => 'python:3.12']);

        $this->assertStringContainsString('ENV HOST=0.0.0.0', $dockerfile);
        $this->assertStringContainsString('ENV PORT=8000', $dockerfile);
        $this->assertStringContainsString('EXPOSE 8000', $dockerfile);
    }

    public function test_a_port_hint_reaches_both_the_environment_and_the_expose(): void
    {
        // These two must agree, or the proxy is pointed at a port nothing is
        // listening on.
        $dockerfile = $this->render(['image' => 'golang:1.22', 'port_hint' => 9000]);

        $this->assertStringContainsString('ENV PORT=9000', $dockerfile);
        $this->assertStringContainsString('EXPOSE 9000', $dockerfile);
    }

    public function test_the_manifests_environment_can_override_the_defaults(): void
    {
        $dockerfile = $this->render([
            'image' => 'python:3.12',
            'env' => ['HOST' => '::', 'DJANGO_SETTINGS_MODULE' => 'app.settings.production'],
        ]);

        $this->assertStringContainsString('ENV HOST=::', $dockerfile);
        $this->assertStringContainsString('ENV DJANGO_SETTINGS_MODULE=app.settings.production', $dockerfile);
        $this->assertStringNotContainsString('ENV HOST=0.0.0.0', $dockerfile);
    }

    public function test_the_staged_entrypoint_is_installed_rather_than_a_start_command(): void
    {
        // ENTRYPOINT, not CMD, and the script branches on the deploy phase -
        // which is why migrations no longer run on every container restart.
        $dockerfile = $this->render(['image' => 'python:3.12']);

        $this->assertStringContainsString('ENTRYPOINT ["/panelalpha-entrypoint.sh"]', $dockerfile);
        $this->assertStringContainsString('RUN chmod +x /panelalpha-entrypoint.sh', $dockerfile);
        $this->assertStringNotContainsString('CMD ', $dockerfile);
    }

    public function test_the_source_is_copied_in(): void
    {
        $dockerfile = $this->render(['image' => 'python:3.12']);

        $this->assertStringContainsString('WORKDIR /app', $dockerfile);
        $this->assertStringContainsString('COPY . .', $dockerfile);
    }

    public function test_a_decision_with_no_image_still_produces_a_buildable_file(): void
    {
        $dockerfile = $this->render([]);

        $this->assertStringContainsString('FROM node:', $dockerfile);
    }

    /**
     * Memtly.Core's csproj runs `npm ci` during publish; the SDK image has no
     * Node, so the build died with MSB3073 / exit 127.
     */
    public function test_a_build_needing_node_gets_it_copied_into_the_build_stage(): void
    {
        $dockerfile = $this->render([
            'image' => 'mcr.microsoft.com/dotnet/sdk:10.0',
            'runtime_image' => 'mcr.microsoft.com/dotnet/aspnet:10.0',
            'output_directory' => 'out',
            'install_command' => 'dotnet publish -c Release -o out',
            'build_node_image' => 'node:20-bookworm-slim',
        ]);
        [$build, $runtime] = explode("\nFROM ", $dockerfile, 2);

        $this->assertStringContainsString('COPY --from=node:20-bookworm-slim /usr/local/bin/ /usr/local/bin/', $build);
        $this->assertStringContainsString('COPY --from=node:20-bookworm-slim /usr/local/lib/node_modules/', $build);
        $this->assertLessThan(strpos($build, 'RUN dotnet publish'), strpos($build, '--from=node:20'));
        $this->assertStringNotContainsString('node:20', $runtime, 'Node stays out of the image that runs');
    }

    public function test_a_build_not_needing_node_gets_none(): void
    {
        $dockerfile = $this->render([
            'image' => 'mcr.microsoft.com/dotnet/sdk:8.0',
            'runtime_image' => 'mcr.microsoft.com/dotnet/aspnet:8.0',
            'output_directory' => 'out',
        ]);

        $this->assertStringNotContainsString('node', $dockerfile);
    }
}
