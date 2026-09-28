<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use PHPUnit\Framework\TestCase;

class DockerfileBuilderTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/fw-dockerfile-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function test_nextjs_node_dockerfile_installs_builds_and_starts(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->write('package-lock.json', '{}');
        $this->write('next.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $files = ProjectContext::listRootFiles($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, $files, $this->tmpDir);

        $this->assertStringContainsString('FROM ' . Images::NODE_IMAGE, $docker);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends git', $docker);
        $this->assertStringContainsString('COPY package-lock.json ./', $docker);
        // The install carries a BuildKit cache mount between RUN and the
        // command, so npm's download cache never enters the layer.
        $this->assertStringContainsString('--mount=type=cache,target=/root/.npm,sharing=locked', $docker);
        $this->assertStringContainsString('HUSKY=0 LEFTHOOK=0 CI=1 npm ci', $docker);
        $this->assertStringContainsString('node -e ', $docker);
        $this->assertStringContainsString('RUN npm run build', $docker);
        $this->assertStringContainsString('EXPOSE 3000', $docker);
        $this->assertStringContainsString('ENV HOST=0.0.0.0', $docker);
        // The start command moved out of the Dockerfile into the staged
        // entrypoint; the image's job is to install it.
        $this->assertStringContainsString('ENTRYPOINT ["/panelalpha-entrypoint.sh"]', $docker);
        $this->assertStringNotContainsString('CMD ', $docker);
        $this->assertStringNotContainsString('FROM ' . Images::NGINX_IMAGE, $docker);
    }

    public function test_package_json_setup_runs_once_before_start(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
                'setup' => 'prisma migrate deploy',
            ],
        ]));
        $this->write('package-lock.json', '{}');
        $this->write('next.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        // The recipe still reports the project's setup script; it is now run
        // by the entrypoint's install stage rather than guarded by a marker
        // file that every image rebuild wiped.
        $this->assertSame('npm run setup', $recipe['setup_command']);
        // The marker file is gone: PA_DEPLOY_PHASE decides whether this boot
        // is an install, and unlike a marker inside the image it survives a
        // rebuild.
        $this->assertStringNotContainsString('.panelalpha-bootstrapped', $docker);
        $this->assertStringContainsString('ENTRYPOINT ["/panelalpha-entrypoint.sh"]', $docker);
    }

    public function test_tanstack_start_is_node_nitro_not_nginx_dist(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['@tanstack/react-start' => '1.168.32', 'vite' => '8.2.0'],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->write('bun.lock', "{}\n");
        $this->write('bunfig.toml', '');
        $this->write('vite.config.ts', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertSame(Strategies::TANSTACK, $recipe['strategy']);
        $this->assertStringContainsString('COPY bun.lock ./', $docker);
        $this->assertStringContainsString('COPY bunfig.toml ./', $docker);
        $this->assertStringContainsString('ENV NITRO_PRESET=node-server', $docker);
        // The serve command is in the entrypoint; what the image must show is
        // that it is a Node runtime, not an nginx one.
        $this->assertStringContainsString('ENTRYPOINT ["/panelalpha-entrypoint.sh"]', $docker);
        $this->assertStringNotContainsString('FROM ' . Images::NGINX_IMAGE, $docker);
        $this->assertStringNotContainsString('/app/dist', $docker);
        $nitroAt = strpos($docker, 'ENV NITRO_PRESET=node-server');
        $buildAt = strpos($docker, 'RUN bun run build');
        $this->assertNotFalse($nitroAt);
        $this->assertNotFalse($buildAt);
        $this->assertLessThan($buildAt, $nitroAt);
    }

    public function test_preload_images_are_the_recipe_base_layers(): void
    {
        $images = Images::preloadImages();

        $this->assertSame([
            Images::NODE_IMAGE,
            Images::NGINX_IMAGE,
            Images::PHP_IMAGE,
            'php:8.4-apache-bookworm',
            'php:8.5-apache-bookworm',
            Images::COMPOSER_IMAGE,
        ], $images);
    }

    public function test_preload_images_for_static_is_empty(): void
    {
        $this->assertSame([], Images::preloadImagesFor(Strategies::STATIC));
        $this->assertSame([], Images::preloadImagesFor(Strategies::RAILPACK));
        $this->assertSame([], Images::preloadImagesFor(Strategies::FALLBACK));
        $this->assertSame([], Images::preloadImagesFor(null));
    }

    /**
     * One image, not four. A PHP account used to pull every php minor a
     * project might resolve to plus composer, because its own build stage
     * needed them; it runs the shared base image now and builds nothing, so
     * the base tag is the whole answer.
     */
    public function test_preload_images_for_php_and_vite(): void
    {
        $this->assertSame(
            [PhpBaseImage::tag(Images::PHP_IMAGE)],
            Images::preloadImagesFor(Strategies::PHP)
        );
        // No Node recipe builds an image any more: every one of them runs our
        // stock image against the mounted project, so its runtime image comes
        // from the compose file's `image:` key rather than from a preload.
        foreach ([Strategies::NEXTJS, Strategies::NESTJS, Strategies::EXPRESS, Strategies::REMIX] as $node) {
            $this->assertSame(
                [],
                Images::preloadImagesFor($node, PlatformManifest::RUNTIME_NODE),
                $node
            );
        }
        $this->assertSame(
            [Images::NODE_IMAGE, Images::NGINX_IMAGE],
            Images::preloadImagesFor(Strategies::VITE, PlatformManifest::RUNTIME_NGINX)
        );
        $this->assertSame(
            [],
            Images::preloadImagesFor(Strategies::TANSTACK, PlatformManifest::RUNTIME_NODE)
        );
        // Go runs our image against the mounted project; its runtime image
        // is provided from the deploy snapshot, not from a preload constant.
        $this->assertSame([], Images::preloadImagesFor(Strategies::GO));
    }

    public function test_preload_images_for_vite_follows_engines_node(): void
    {
        $this->write('package.json', json_encode([
            'engines' => ['node' => '^20.19.0 || >=22.12.0'],
            'devDependencies' => ['vite' => '6.0.0'],
        ]));
        $this->write('vite.config.ts', 'export default {};');

        $this->assertSame(
            ['node:20-bookworm-slim', Images::NGINX_IMAGE],
            Images::preloadImagesFor(
                Strategies::VITE,
                PlatformManifest::RUNTIME_NGINX,
                $this->tmpDir
            )
        );
    }

    /** The Node image a .NET build copies from is preloaded with the SDK. */
    public function test_preload_images_for_dotnet_includes_node_when_a_target_runs_npm(): void
    {
        $this->write('App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><TargetFramework>net8.0</TargetFramework></Project>');
        $plain = Images::preloadImagesFor(Strategies::DOTNET, null, $this->tmpDir);
        $this->assertNotContains(Images::NODE_IMAGE, $plain);

        $this->write('App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><TargetFramework>net8.0</TargetFramework>'
            . '<Target Name="Npm"><Exec Command="npm ci" /></Target></Project>');
        $this->assertSame(
            [...$plain, Images::NODE_IMAGE],
            Images::preloadImagesFor(Strategies::DOTNET, null, $this->tmpDir)
        );
    }

    public function test_node_image_follows_engines_field(): void
    {
        // Deliberately a major the default is not, so this proves the engines
        // field decides rather than coinciding with the fallback.
        $this->write('package.json', json_encode([
            'engines' => ['node' => '>=22'],
            'dependencies' => ['express' => '4.21.0'],
        ]));
        $this->write('index.js', 'require("express")');

        $this->assertSame('node:22-bookworm-slim', Images::nodeImage($this->tmpDir));
        $this->assertNotSame(Images::NODE_IMAGE, Images::nodeImage($this->tmpDir));

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate(
            $recipe,
            ProjectContext::listRootFiles($this->tmpDir),
            $this->tmpDir
        );
        $this->assertStringContainsString('FROM node:22-bookworm-slim', $docker);
        $this->assertStringNotContainsString('FROM ' . Images::NODE_IMAGE, $docker);
    }

    public function test_node_image_follows_nvmrc(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('.nvmrc', "v18\n");
        $this->write('index.js', 'require("express")');

        $this->assertSame('node:18-bookworm-slim', Images::nodeImage($this->tmpDir));
    }

    public function test_vite_uses_multistage_nginx_and_spa_conf(): void
    {
        $this->write('package.json', json_encode([
            'devDependencies' => ['vite' => '6.0.0'],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->write('vite.config.ts', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertStringContainsString('FROM ' . Images::NODE_IMAGE . ' AS builder', $docker);
        $this->assertStringContainsString('FROM ' . Images::NGINX_IMAGE, $docker);
        $this->assertStringContainsString('COPY --from=builder /app/dist /usr/share/nginx/html', $docker);
        $this->assertStringContainsString('EXPOSE 80', $docker);
        $this->assertStringContainsString('try_files $uri $uri/ /index.html', NginxConfig::contents());
    }

    public function test_astro_static_copies_dist_not_a_node_cmd(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['astro' => '5.0.0']]));
        $this->write('astro.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $recipe['runtime']);
        $this->assertStringContainsString('COPY --from=builder /app/dist /usr/share/nginx/html', $docker);
        $this->assertStringNotContainsString('CMD ', $docker);
    }

    public function test_express_without_build_script_skips_build_run(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('index.js', 'require("express")');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertSame(Strategies::EXPRESS, $recipe['strategy']);
        $this->assertSame('node index.js', $recipe['start_command']);
        // asserted on the recipe, not the Dockerfile — see the entrypoint
        $this->assertStringNotContainsString('RUN npm run build', $docker);
        $this->assertStringContainsString('ENTRYPOINT ["/panelalpha-entrypoint.sh"]', $docker);
    }

    public function test_yarn_lock_uses_yarn_install(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->write('yarn.lock', "# yarn lockfile v1\n");
        $this->write('next.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertSame('yarn', $recipe['package_manager']);
        $this->assertStringContainsString('COPY yarn.lock ./', $docker);
        $this->assertStringContainsString('yarn install --frozen-lockfile', $docker);
        $this->assertStringContainsString('RUN yarn build', $docker);
    }

    public function test_pnpm_install_allows_dependency_build_scripts(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['astro' => '7.0.0'],
            'scripts' => ['build' => 'astro build'],
        ]));
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $this->write('astro.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertSame('pnpm', $recipe['package_manager']);
        $this->assertStringContainsString('COPY pnpm-lock.yaml ./', $docker);
        $this->assertStringContainsString('corepack prepare pnpm@10 --activate', $docker);
        $this->assertStringContainsString('pnpm install --frozen-lockfile --dangerously-allow-all-builds', $docker);
        $this->assertStringNotContainsString('pnpm-workspace.yaml', $docker);
    }

    public function test_pnpm_pins_package_manager_field_and_skips_allow_flag_before_10_9(): void
    {
        $this->write('package.json', json_encode([
            'packageManager' => 'pnpm@9.15.4',
            'dependencies' => ['astro' => '7.0.0'],
            'scripts' => ['build' => 'astro build'],
        ]));
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $this->write('astro.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);

        $this->assertSame(
            'HUSKY=0 LEFTHOOK=0 CI=1 ' . JsPackageManager::ENSURE_COREPACK
                . ' && corepack enable && corepack prepare pnpm@9.15.4 --activate && pnpm install --frozen-lockfile',
            $recipe['install_command']
        );
        $this->assertStringNotContainsString('--dangerously-allow-all-builds', $recipe['install_command']);
    }

    public function test_pnpm_copies_existing_workspace_file_without_rewriting_it(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['astro' => '7.0.0'],
            'scripts' => ['build' => 'astro build'],
        ]));
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $this->write('pnpm-workspace.yaml', "packages:\n  - packages/*\n");
        $this->write('astro.config.mjs', 'export default {};');

        $docker = DockerfileBuilder::generate(
            DetectProjectStrategy::detect($this->tmpDir),
            ProjectContext::listRootFiles($this->tmpDir),
            $this->tmpDir
        );

        // Workspace roots COPY the whole tree before install so every
        // package.json is present (pnpm-workspace.yaml included).
        $this->assertStringContainsString('COPY . .', $docker);
        $this->assertStringContainsString('corepack enable', $docker);
        $this->assertLessThan(
            strpos($docker, 'corepack enable'),
            strpos($docker, 'COPY . .')
        );
        $this->assertStringNotContainsString('>> pnpm-workspace.yaml', $docker);
    }

    public function test_bun_workspace_uses_oven_bun_and_copies_tree_before_install(): void
    {
        $this->write('package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
            'scripts' => ['build' => 'turbo build', 'start' => 'turbo start'],
            'dependencies' => ['turbo' => '1'],
        ]));
        $this->write('bun.lockb', "\0");
        mkdir($this->tmpDir . '/apps/next', 0777, true);
        $this->write('apps/next/package.json', json_encode([
            'name' => '@app/next',
            'dependencies' => ['next' => '14.0.0'],
        ]));
        $this->write('apps/next/next.config.mjs', 'export default {};');

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate(
            $recipe,
            ProjectContext::listRootFiles($this->tmpDir),
            $this->tmpDir
        );

        $this->assertSame('bun', $recipe['package_manager']);
        $this->assertSame('HUSKY=0 LEFTHOOK=0 CI=1 bun install', $recipe['install_command']);
        $this->assertStringContainsString('FROM ' . HostNodeBuild::BUN_IMAGE, $docker);
        $this->assertStringContainsString('bun -e ', $docker);
        $this->assertStringContainsString('lefthook', $docker);
        $this->assertStringContainsString("CI=1 bun install\n", $docker);
        $this->assertStringNotContainsString('npm install -g bun', $docker);
        $this->assertStringNotContainsString('--frozen-lockfile', $docker);
    }

    public function test_next_export_copies_out_directory(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['next' => '15.0.0']]));
        $this->write('next.config.js', "module.exports = { output: 'export' };\n");

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertStringContainsString('COPY --from=builder /app/out /usr/share/nginx/html', $docker);
    }

    public function test_language_recipe_uses_official_image_not_node(): void
    {
        $this->write('go.mod', "module example.com/app\n\ngo 1.22\n");
        $this->write('main.go', "package main\nfunc main() {}\n");

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate($recipe, ProjectContext::listRootFiles($this->tmpDir), $this->tmpDir);

        $this->assertSame(Strategies::GO, $recipe['strategy']);
        $this->assertStringContainsString('FROM ' . GoRuntime::IMAGE, $docker);
        $this->assertStringContainsString('go build -o app .', $docker);
        $this->assertStringNotContainsString('FROM ' . Images::NODE_IMAGE, $docker);
    }

    public function test_go_123_uses_matching_official_image(): void
    {
        $this->write('go.mod', "module example.com/app\n\ngo 1.23\n");
        $this->write('main.go', "package main\nfunc main() {}\n");

        $recipe = DetectProjectStrategy::detect($this->tmpDir);
        $docker = DockerfileBuilder::generate(
            $recipe,
            ProjectContext::listRootFiles($this->tmpDir),
            $this->tmpDir
        );

        $this->assertSame('golang:1.23-alpine', $recipe['image']);
        $this->assertStringContainsString('FROM golang:1.23-alpine', $docker);
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->tmpDir . '/' . $relative, $contents);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /**
     * A project that says nothing about Node gets an older LTS, not the newest
     * release. Newer Node is where an untouched project's dependencies break,
     * and older runs nearly everything newer runs.
     */
    public function test_a_project_that_declares_no_node_version_gets_a_conservative_lts(): void
    {
        $this->write('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->write('index.js', 'require("express")');

        $image = Images::nodeImage($this->tmpDir);

        $this->assertSame(Images::NODE_IMAGE, $image);
        $this->assertMatchesRegularExpression('/^node:(18|20)-/', $image);
    }
}
