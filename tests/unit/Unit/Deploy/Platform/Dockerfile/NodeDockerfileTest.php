<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\BuildRecipe;
use App\Lib\Deploy\Platform\Dockerfile\NodeDockerfile;
use App\Lib\Deploy\Platform\Dockerfile\NodeBaseImage;
use App\Lib\Deploy\Platform\Dockerfile\StaticSiteDockerfile;
use Tests\TestCase;

/**
 * The two JS shapes: a server that keeps its runtime, and a site that throws
 * it away.
 *
 * They share an install layer and differ in what ships. A static site that
 * shipped the Node toolchain would be a 400MB image serving files nginx could
 * serve from 40MB; a server built as a static site would have no process to
 * run at all.
 */
class NodeDockerfileTest extends TestCase
{
    /**
     * @param array<string, mixed> $decision
     * @param array<string, true> $files
     */
    private function node(array $decision, array $files = ['package.json' => true]): string
    {
        return (new NodeDockerfile(new BuildRecipe($decision, $files)))->render();
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, true> $files
     */
    private function static(array $decision, array $files = ['package.json' => true]): string
    {
        return (new StaticSiteDockerfile(new BuildRecipe($decision, $files)))->render();
    }

    public function test_a_server_installs_builds_and_hands_over_to_the_entrypoint(): void
    {
        $dockerfile = $this->node([
            'install_command' => 'npm ci',
            'build_command' => 'npm run build',
        ]);

        // The cache mount sits between RUN and the command; see PackageManagerCache.
        $this->assertStringContainsString('--mount=type=cache,target=/root/.npm', $dockerfile);
        $this->assertStringContainsString('npm ci', $dockerfile);
        $this->assertStringContainsString('RUN npm run build', $dockerfile);
        $this->assertStringContainsString('ENTRYPOINT ["/panelalpha-entrypoint.sh"]', $dockerfile);
    }

    public function test_a_server_is_told_where_to_listen(): void
    {
        $dockerfile = $this->node([]);

        $this->assertStringContainsString('ENV NODE_ENV=production', $dockerfile);
        $this->assertStringContainsString('ENV HOST=0.0.0.0', $dockerfile);
        $this->assertStringContainsString('ENV HOSTNAME=0.0.0.0', $dockerfile);
        $this->assertStringContainsString('ENV PORT=3000', $dockerfile);
        $this->assertStringContainsString('EXPOSE 3000', $dockerfile);
    }

    public function test_a_port_hint_reaches_the_environment_and_the_expose(): void
    {
        $dockerfile = $this->node(['port_hint' => 4321]);

        $this->assertStringContainsString('ENV PORT=4321', $dockerfile);
        $this->assertStringContainsString('EXPOSE 4321', $dockerfile);
    }

    public function test_a_recipes_environment_can_override_the_defaults(): void
    {
        $dockerfile = $this->node(['env' => ['NODE_ENV' => 'staging']]);

        $this->assertStringContainsString('ENV NODE_ENV=staging', $dockerfile);
        $this->assertStringNotContainsString('ENV NODE_ENV=production', $dockerfile);
    }

    /**
     * A JS build inside a generated Dockerfile runs in the account's daemon,
     * which caps nothing by itself and has no `--memory` flag to size Node
     * from. Without this line a large `next build` dies on
     * `Ineffective mark-compacts near heap limit` at 1 GB inside a 2 GB
     * build container -- the host compile's heap cap does not reach here.
     */
    public function test_a_server_builds_inside_the_heap_the_caller_resolved(): void
    {
        $dockerfile = $this->node([
            'node_heap_mb' => 1433,
            'install_command' => 'npm ci',
        ]);

        $this->assertStringContainsString('ARG NODE_OPTIONS=--max-old-space-size=1433', $dockerfile);
        // Above the install, or the install is what runs uncapped. The cache
        // mount sits between `RUN` and the command, so match the command.
        $this->assertLessThan(
            strpos($dockerfile, 'npm ci'),
            strpos($dockerfile, 'ARG NODE_OPTIONS'),
            'the heap must be declared before the dependency install'
        );
    }

    /**
     * A build arg and not an `ENV`: the number is 70% of the engine's build
     * container, while the application runs in an account container that is
     * usually smaller. A `--max-old-space-size` above the cgroup is worse than
     * none -- V8 tries to reach it and the kernel kills the process before a
     * collection happens -- so it must not survive into the image.
     */
    public function test_a_server_does_not_bake_the_build_heap_into_the_runtime(): void
    {
        $dockerfile = $this->node(['node_heap_mb' => 1433]);

        $this->assertStringNotContainsString('ENV NODE_OPTIONS', $dockerfile);
    }

    /**
     * A heap the caller did not resolve leaves the image exactly as it was:
     * the writer runs for a non-JS project too, and a stray V8 variable there
     * is noise rather than a default.
     */
    public function test_a_server_without_a_resolved_heap_declares_none(): void
    {
        $this->assertStringNotContainsString('NODE_OPTIONS', $this->node([]));
    }

    /**
     * The static shape throws the toolchain away, so the heap belongs in the
     * builder stage that runs it and nowhere near the nginx that ships.
     */
    public function test_a_static_site_declares_the_heap_in_the_builder_stage(): void
    {
        $dockerfile = $this->static(['node_heap_mb' => 1433, 'build_command' => 'npm run build']);

        $this->assertStringContainsString('ARG NODE_OPTIONS=--max-old-space-size=1433', $dockerfile);
        $this->assertLessThan(
            strpos($dockerfile, 'FROM nginx:alpine'),
            strpos($dockerfile, 'ARG NODE_OPTIONS'),
            'the heap must be declared while the toolchain is still the stage'
        );
    }

    public function test_a_static_site_ships_nginx_and_not_the_toolchain(): void
    {
        // Two stages: only the build output crosses over.
        $dockerfile = $this->static(['build_command' => 'npm run build', 'output_directory' => 'dist']);

        $this->assertStringContainsString('AS builder', $dockerfile);
        $this->assertStringContainsString('FROM nginx:alpine', $dockerfile);
        $this->assertStringContainsString(
            'COPY --from=builder /app/dist /usr/share/nginx/html',
            $dockerfile
        );
        $this->assertStringContainsString('EXPOSE 80', $dockerfile);
    }

    public function test_a_static_site_carries_its_own_nginx_configuration(): void
    {
        // Without it, a single-page app 404s on every route but `/`.
        $dockerfile = $this->static([]);

        $this->assertStringContainsString(
            'COPY panelalpha.nginx.conf /etc/nginx/conf.d/default.conf',
            $dockerfile
        );
    }

    public function test_a_static_site_copies_the_directory_the_build_wrote(): void
    {
        $dockerfile = $this->static(['output_directory' => 'dist/my-app/browser']);

        $this->assertStringContainsString('/app/dist/my-app/browser', $dockerfile);
    }

    public function test_an_output_directory_cannot_escape_the_build(): void
    {
        // The value comes from a project's own config file.
        $dockerfile = $this->static(['output_directory' => '../../etc']);

        $this->assertStringContainsString('COPY --from=builder /app/dist ', $dockerfile);
        $this->assertStringNotContainsString('..', $dockerfile);
    }

    public function test_a_static_site_has_no_entrypoint(): void
    {
        // There is no process of the project's to run; nginx is the process.
        $this->assertStringNotContainsString('panelalpha-entrypoint.sh', $this->static([]));
    }

    public function test_a_bun_project_builds_in_the_bun_image(): void
    {
        $recipe = new BuildRecipe(['package_manager' => 'bun'], ['package.json' => true, 'bun.lock' => true]);

        $this->assertSame('oven/bun:1', NodeBaseImage::for($recipe));
    }

    public function test_a_recipes_node_image_is_honoured(): void
    {
        $recipe = new BuildRecipe(['image' => 'node:22-bookworm-slim']);

        $this->assertSame('node:22-bookworm-slim', NodeBaseImage::for($recipe));
    }

    public function test_an_image_that_is_not_a_node_image_does_not_become_the_base(): void
    {
        // A recipe naming a runtime image for the deployed container says
        // nothing about which Node should compile it.
        $recipe = new BuildRecipe(['image' => 'ghcr.io/acme/app:latest']);

        $this->assertStringStartsWith('node:', NodeBaseImage::for($recipe));
    }

    /**
     * A recipe whose project has to compile a dependency builds FROM the full
     * variant of the image it named.
     *
     * The recipe's tag has no `-slim` stripped by hand here: the swap is the
     * same {@see \App\Lib\Deploy\Platform\Runtime\Images::nodeBuildImage()}
     * the host compile goes through, so both ends of a deploy agree. Without
     * it the NodeDockerfile path — a repository that ships its own build in a
     * generated image — kept building on an image with no `node-gyp`.
     */
    public function test_a_native_project_builds_from_the_full_variant(): void
    {
        $dir = sys_get_temp_dir() . '/node-native-df-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/package.json', json_encode([
            'dependencies' => ['better-sqlite3' => '^12.0.0'],
        ]));

        try {
            $recipe = new BuildRecipe(
                ['image' => 'node:22-bookworm-slim'],
                ['package.json' => true],
                $dir
            );

            $this->assertSame('node:22-bookworm', NodeBaseImage::for($recipe));
        } finally {
            unlink($dir . '/package.json');
            rmdir($dir);
        }
    }
}
