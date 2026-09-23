<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Platform\AppConfig\PaemdPage;
use App\Lib\Deploy\Detect\EditorExtension;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\DetectProjectStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DetectProjectStrategy.
 *
 * The class has no Laravel dependencies, so this test extends the plain
 * PHPUnit TestCase — no app boot required. Each test uses an isolated
 * temp directory created in setUp() and removed in tearDown().
 */
class DetectProjectStrategyTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/detect-strategy-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function writeFile(string $relative, string $contents = ''): void
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
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

    private function detect(): array
    {
        return DetectProjectStrategy::detect($this->tmpDir);
    }

    /**
     * Detection does not read `panelalpha.md`. The page is a bootstrap: it
     * runs first and edits the checkout, and this then reads the result — so a
     * compose file a page wrote and one the repository shipped are the same
     * file here, which is the whole reason there is no branch for either.
     */
    public function test_a_compose_file_is_a_compose_file_whoever_wrote_it(): void
    {
        $this->writeFile('Dockerfile', "FROM nginx\n");
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    image: n8nio/n8n\n");
        $this->writeFile('panelalpha.md', "- `panelalpha-app.sh`\n```bash\necho hi\n```\n");

        $result = $this->detect();

        // compose is priority 980, dockerfile 970.
        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
    }

    /**
     * One case per branch of the strategy dispatch, so the table that replaced
     * five hand-written `if`s cannot lose one silently.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function undeployableStrategies(): array
    {
        return [
            'dockerfile' => [['strategy' => Strategies::DOCKERFILE], 'Dockerfile is missing'],
            'compose' => [['strategy' => Strategies::COMPOSE], 'compose file is missing'],
            'static' => [['strategy' => Strategies::STATIC], 'no HTML file is present'],
            'js framework' => [['strategy' => Strategies::NEXTJS], 'Framework strategy selected but package.json'],
            'laravel' => [['strategy' => Strategies::LARAVEL], 'PHP strategy selected but composer.json'],
            'php' => [['strategy' => Strategies::PHP], 'PHP strategy selected but composer.json'],
            'rails' => [['strategy' => Strategies::RAILS], 'Ruby strategy selected but Gemfile'],
            'ruby' => [['strategy' => Strategies::RUBY], 'Ruby strategy selected but Gemfile'],
        ];
    }

    /**
     * @param array<string, mixed> $decision
     */
    #[DataProvider('undeployableStrategies')]
    public function test_each_strategy_reports_the_file_it_needs(array $decision, string $expected): void
    {
        $this->writeFile('README.md', 'not empty, but not deployable either');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        DeployabilityCheck::assert($decision, $this->tmpDir);
    }

    public function test_a_strategy_that_needs_no_particular_file_is_deployable(): void
    {
        $this->writeFile('README.md', 'hello');

        DeployabilityCheck::assert(['strategy' => Strategies::RAILPACK], $this->tmpDir);
        $this->assertTrue(true, 'railpack asks for nothing in the root');
    }

    public function test_compose_yaml_wins_over_dockerfile(): void
    {
        $this->writeFile('compose.yaml', "services:\n  app:\n    image: nginx\n");
        $this->writeFile('Dockerfile', "FROM nginx\n");

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
        $this->assertSame('Docker Compose', $result['label']);
        $this->assertSame($this->tmpDir . '/compose.yaml', $result['compose_path']);
    }

    public function test_compose_missing_devcontainer_dockerfile_keeps_compose_when_root_dockerfile_exists(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    build:\n      context: .\n      dockerfile: .devcontainer/Dockerfile\n      target: development\n    ports:\n      - '8080:3000'\n");
        $this->writeFile('Dockerfile', "FROM ruby:4.0.1-slim\nEXPOSE 3000\n");

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
        $this->assertSame(
            ['.devcontainer/Dockerfile'],
            ComposeFileInspector::missingComposeDockerfileRefs($this->tmpDir . '/docker-compose.yml', $this->tmpDir)
        );
        DeployabilityCheck::assert($result, $this->tmpDir);
    }

    public function test_compose_missing_dockerfile_falls_through_without_root_dockerfile(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    build:\n      context: .\n      dockerfile: .devcontainer/Dockerfile\n");
        $this->writeFile('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->writeFile('index.js', 'require("express")');

        $result = $this->detect();

        $this->assertSame(Strategies::EXPRESS, $result['strategy']);
    }

    public function test_compose_dockerfile_is_resolved_relative_to_build_context(): void
    {
        $this->writeFile(
            'docker-compose.yml',
            "services:\n"
            . "  console:\n"
            . "    build:\n"
            . "      context: ./console\n"
            . "      dockerfile: Dockerfile\n"
            . "    ports:\n"
            . "      - '4200:4200'\n"
            . "  httpd:\n"
            . "    build:\n"
            . "      context: .\n"
            . "      dockerfile: docker/httpd/Dockerfile\n"
            . "    ports:\n"
            . "      - '8000:80'\n"
            . "  api:\n"
            . "    image: example/api:latest\n"
        );
        $this->writeFile('console/Dockerfile', "FROM nginx\n");
        $this->writeFile('docker/httpd/Dockerfile', "FROM httpd\n");
        $this->writeFile('docker/Dockerfile', "FROM php:8.2\n");

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
        $this->assertSame(
            [],
            ComposeFileInspector::missingComposeDockerfileRefs($this->tmpDir . '/docker-compose.yml', $this->tmpDir)
        );
    }

    public function test_compose_string_build_context_looks_for_dockerfile_inside_context(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    build: ./api\n");
        $this->writeFile('api/Dockerfile', "FROM php:8.3-cli\n");
        $this->writeFile('docker/Dockerfile', "FROM php:8.2\n");

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
    }

    public function test_compose_candidate_priority(): void
    {
        $this->writeFile('docker-compose.yml', "services: {}\n");
        $this->writeFile('compose.yml', "services: {}\n");

        $result = $this->detect();

        $this->assertSame($this->tmpDir . '/compose.yml', $result['compose_path']);
    }

    public function test_dockerfile_wins_over_railpack_manifest(): void
    {
        $this->writeFile('Dockerfile', "FROM node:20\nEXPOSE 3000\nCMD node index.js\n");
        $this->writeFile('package.json', '{"name":"app"}');

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
        $this->assertSame('Dockerfile', $result['label']);
        $this->assertSame('Dockerfile', $result['dockerfile']);
        $this->assertSame(3000, $result['port_hint']);
    }

    public function test_explicit_dockerfile_wins_over_rails_recipe(): void
    {
        $this->writeFile('Dockerfile', "FROM ruby:4.0\nEXPOSE 3000\n");
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\ngem 'rails'\n");
        $this->writeFile('config/application.rb', "module App\nclass Application < Rails::Application\nend\nend\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
        $this->assertSame('Dockerfile', $result['label']);
        $this->assertSame(3000, $result['port_hint']);
    }

    public function test_dockerfile_variant_filename(): void
    {
        $this->writeFile('Dockerfile.web', "FROM nginx\nEXPOSE 8080\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
        $this->assertSame('Dockerfile.web', $result['dockerfile']);
        $this->assertSame(8080, $result['port_hint']);
    }

    public function test_railpack_from_package_json(): void
    {
        $this->writeFile('package.json', '{"name":"app"}');

        $result = $this->detect();

        $this->assertSame(Strategies::RAILPACK, $result['strategy']);
        $this->assertSame('Railpack', $result['label']);
    }

    public function test_nextjs_wins_over_railpack(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'app',
            'dependencies' => ['next' => '15.0.0', 'react' => '19.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('next.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertSame('Next.js', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame(3000, $result['port_hint']);
        $this->assertSame('npm start', $result['start_command']);
        $this->assertSame('npm run build', $result['build_command']);
        DeployabilityCheck::assert($result, $this->tmpDir);
    }

    public function test_nextjs_monorepo_under_apps_wins_over_railpack(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'quenti-monorepo',
            'private' => true,
            'workspaces' => ['apps/*', 'packages/*'],
            'packageManager' => 'bun@1.0.2',
            'scripts' => [
                'build' => 'dotenv -- turbo build --filter @quenti/next...',
                'start' => 'dotenv -- turbo start --scope "@quenti/next"',
            ],
            'dependencies' => [
                'turbo' => 'latest',
                'eslint-config-next' => '^14.0.4',
            ],
        ]));
        $this->writeFile('bun.lockb', "\0");
        mkdir($this->tmpDir . '/apps/next', 0777, true);
        $this->writeFile('apps/next/package.json', json_encode([
            'name' => '@quenti/next',
            'dependencies' => ['next' => '14.0.4', 'react' => '18.2.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('apps/next/next.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertSame('Next.js', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame(3000, $result['port_hint']);
        $this->assertSame('bun', $result['package_manager']);
        $this->assertSame('bun run build', $result['build_command']);
        $this->assertSame('bun run start', $result['start_command']);
        $this->assertSame('HUSKY=0 LEFTHOOK=0 CI=1 bun install', $result['install_command']);
        DeployabilityCheck::assert($result, $this->tmpDir);
    }

    public function test_nextjs_turbo_without_filter_injects_workspace_package(): void
    {
        $this->writeFile('package.json', json_encode([
            'private' => true,
            'packageManager' => 'pnpm@9.0.0',
            'scripts' => [
                'build' => 'turbo build',
                'start' => 'turbo run start',
            ],
            'devDependencies' => ['turbo' => '2.0.0'],
        ]));
        $this->writeFile('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        mkdir($this->tmpDir . '/apps/web', 0777, true);
        $this->writeFile('apps/web/package.json', json_encode([
            'name' => '@acme/web',
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('apps/web/next.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertStringContainsString('--filter=@acme/web...', $result['build_command']);
        $this->assertStringContainsString('--filter=@acme/web...', $result['start_command']);
        $this->assertStringContainsString('pnpm exec turbo', $result['build_command']);
    }

    public function test_nextjs_prefers_build_slug_script_and_storefront_over_docs(): void
    {
        $this->writeFile('package.json', json_encode([
            'private' => true,
            'packageManager' => 'pnpm@9.0.0',
            'scripts' => [
                'build' => 'turbo build',
                'build:storefront' => 'turbo build --filter=storefront...',
                'start:storefront' => 'turbo run start --filter=storefront...',
            ],
        ]));
        $this->writeFile('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        mkdir($this->tmpDir . '/apps/docs', 0777, true);
        mkdir($this->tmpDir . '/apps/storefront', 0777, true);
        $this->writeFile('apps/docs/package.json', json_encode([
            'name' => 'docs',
            'dependencies' => ['next' => '15.0.0'],
        ]));
        $this->writeFile('apps/docs/next.config.mjs', 'export default {};');
        $this->writeFile('apps/storefront/package.json', json_encode([
            'name' => 'storefront',
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('apps/storefront/next.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertSame('pnpm run build:storefront', $result['build_command']);
        $this->assertSame('pnpm run start:storefront', $result['start_command']);
    }

    public function test_sidecars_only_compose_defers_to_nextjs(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('next.config.mjs', 'export default {};');
        $this->writeFile(
            'docker-compose.yml',
            "services:\n  postgres:\n    image: postgres:16\n  redis:\n    image: redis:7\n"
        );

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertTrue(ComposeFileInspector::isSidecarsOnlyComposeYaml(
            "services:\n  postgres:\n    image: postgres:16\n  redis:\n    image: redis:7\n"
        ));
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml(
            "services:\n  web:\n    image: ghcr.io/acme/web:latest\n  postgres:\n    image: postgres:16\n"
        ));
        $this->assertTrue(ComposeFileInspector::isSidecarsOnlyComposeYaml(
            "services:\n"
            . "  db:\n    image: postgres:16-alpine\n"
            . "  redis:\n    image: redis\n"
            . "  serverless-redis-http:\n"
            . "    image: hiett/serverless-redis-http:latest\n"
            . "    ports:\n      - '8079:80'\n"
            . "    depends_on: [redis]\n"
        ));
        $this->assertFalse(
            SidecarEngine::looksLikeTheApplication(
                'serverless-redis-http',
                [
                    'image' => 'hiett/serverless-redis-http:latest',
                    'depends_on' => ['redis'],
                ],
                [
                    'redis' => ['image' => 'redis'],
                    'serverless-redis-http' => [
                        'image' => 'hiett/serverless-redis-http:latest',
                        'depends_on' => ['redis'],
                    ],
                ]
            )
        );
    }

    public function test_nextjs_export_uses_nginx_on_out(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build'],
        ]));
        $this->writeFile('next.config.js', "module.exports = { output: 'export' };\n");

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertSame('Next.js (export)', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('out', $result['output_directory']);
        $this->assertSame(8080, $result['port_hint']);
        $this->assertSame('', $result['start_command']);
    }

    public function test_nextjs_wins_over_vite_when_both_present(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0', 'vite' => '6.0.0'],
        ]));
        $this->writeFile('next.config.ts', 'export default {};');
        $this->writeFile('vite.config.ts', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
    }

    public function test_tanstack_start_wins_over_vite_spa(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => [
                '@tanstack/react-start' => '1.168.32',
                'vite' => '8.2.0',
                'nitro' => '3.0.0',
            ],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->writeFile('bun.lock', "{\n}\n");
        $this->writeFile('vite.config.ts', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::TANSTACK, $result['strategy']);
        $this->assertSame('TanStack Start', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame('node ' . StandaloneNodeServe::FILENAME, $result['start_command']);
        $this->assertSame('bun', $result['package_manager']);
        $this->assertSame('node-server', $result['env']['NITRO_PRESET']);
    }

    public function test_tanstack_start_without_nitro_still_expects_a_node_server(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => [
                '@tanstack/react-start' => 'latest',
                'vite' => '7.3.1',
            ],
            'scripts' => ['build' => 'vite build', 'preview' => 'vite preview'],
        ]));
        $this->writeFile('bun.lock', "{\n}\n");
        $this->writeFile(
            'vite.config.ts',
            "import { tanstackStart } from '@tanstack/react-start/plugin/vite'\n"
            . "export default { plugins: [tanstackStart()] }\n"
        );

        $result = $this->detect();

        $this->assertSame(Strategies::TANSTACK, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame('node ' . StandaloneNodeServe::FILENAME, $result['start_command']);
    }

    public function test_astro_default_is_nginx_on_dist(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['astro' => '5.0.0'],
            'scripts' => ['build' => 'astro build'],
        ]));
        $this->writeFile('astro.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::ASTRO, $result['strategy']);
        $this->assertSame('Astro', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('dist', $result['output_directory']);
        $this->assertSame(8080, $result['port_hint']);
    }

    public function test_astro_node_adapter_is_ssr(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['astro' => '5.0.0', '@astrojs/node' => '9.0.0'],
        ]));
        $this->writeFile('astro.config.mjs', "export default { output: 'server' };\n");

        $result = $this->detect();

        $this->assertSame(Strategies::ASTRO, $result['strategy']);
        $this->assertSame('Astro (SSR)', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame(4321, $result['port_hint']);
        $this->assertSame('node ./dist/server/entry.mjs', $result['start_command']);
    }

    public function test_astro_start_astro_dev_is_static_nginx(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['astro' => '4.0.0'],
            'scripts' => [
                'dev' => 'astro dev',
                'start' => 'astro dev',
                'build' => 'astro check && astro build && node process-html.mjs',
            ],
        ]));
        $this->writeFile('astro.config.mjs', "import { defineConfig } from 'astro/config';\nexport default defineConfig({});\n");
        $this->writeFile('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $result = $this->detect();

        $this->assertSame(Strategies::ASTRO, $result['strategy']);
        $this->assertSame('Astro', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('dist', $result['output_directory']);
        $this->assertSame('pnpm', $result['package_manager']);
        $this->assertSame('', $result['start_command']);
        $this->assertSame(
            'PATH=/app/node_modules/.bin:$PATH astro build && node process-html.mjs',
            $result['build_command']
        );
    }

    public function test_astro_start_node_server_is_ssr(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['astro' => '5.0.0'],
            'scripts' => [
                'build' => 'astro build',
                'start' => 'node ./dist/server/entry.mjs',
            ],
        ]));
        $this->writeFile('astro.config.mjs', "export default {};\n");

        $result = $this->detect();

        $this->assertSame('Astro (SSR)', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame('npm start', $result['start_command']);
    }

    public function test_vite_spa_is_nginx_after_build(): void
    {
        $this->writeFile('package.json', json_encode([
            'devDependencies' => ['vite' => '6.0.0'],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->writeFile('vite.config.ts', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::VITE, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('dist', $result['output_directory']);
    }

    /**
     * github.com/kresusapp/kresus: a server-rendered app whose front end is
     * built by Vite.
     *
     * It has `vite` *and* `express` with `typeorm`, a `start` of
     * `node bin/kresus.js`, and its own `build:server`/`build:prod` scripts.
     * Vite ranks above express (890 against 888) because the ordinary Vite
     * project -- an SPA with no server at all -- would otherwise never be
     * reached here. With both present the order is wrong, and the deploy went
     * down the static path and died on
     *
     *     Static build finished but dist/index.html is missing
     *
     * kresus writes `build/client/index.html`: its vite.config sets
     * `root: './client'` and `outDir: '../build/client'`, so `dist` never
     * existed.
     */
    public function test_a_vite_app_with_a_server_is_not_the_static_recipe(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['express' => '~5.2.1', 'typeorm' => '1.1.0'],
            'devDependencies' => ['vite' => '6.0.0'],
            'scripts' => [
                'build' => './scripts/build/dev.sh',
                'build:server' => './scripts/build/server.sh',
                'start' => 'node bin/kresus.js',
            ],
        ]));
        $this->writeFile('vite.config.js', "export default { root: './client', build: { outDir: '../build/client' } };\n");

        $result = $this->detect();

        // express is the recipe that runs it; vite was only the asset step.
        $this->assertSame(Strategies::EXPRESS, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
    }

    /** And the plain SPA still goes to vite, with no server dependency. */
    public function test_the_server_exclusion_does_not_claim_plain_vite_apps(): void
    {
        $this->writeFile('package.json', json_encode([
            'devDependencies' => ['vite' => '6.0.0'],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->writeFile('vite.config.ts', 'export default {};');

        $this->assertSame(Strategies::VITE, $this->detect()['strategy']);
    }

    /** Every server dependency the manifest excludes, not just express. */
    public function test_each_server_dependency_moves_a_vite_app_off_the_static_recipe(): void
    {
        foreach (['express', 'fastify', 'koa', 'hono', 'h3', '@nestjs/core', 'nuxt'] as $dep) {
            $this->writeFile('package.json', json_encode([
                'dependencies' => [$dep => '^1'],
                'devDependencies' => ['vite' => '6.0.0'],
                'scripts' => ['build' => 'vite build'],
            ]));
            $this->writeFile('vite.config.ts', 'export default {};');

            $strategy = $this->detect()['strategy'];
            $this->assertNotSame(Strategies::VITE, $strategy, $dep . ' should not be the static recipe');
        }
    }

    public function test_vite_nginx_prefers_build_only_over_typecheck_build(): void
    {
        $this->writeFile('package.json', json_encode([
            'devDependencies' => ['vite' => '8.0.0'],
            'scripts' => [
                'build' => 'run-p type-check "build-only {@}" --',
                'build-only' => 'vite build',
                'type-check' => 'vue-tsc --build',
            ],
        ]));
        $this->writeFile('vite.config.ts', 'export default {};');
        $this->writeFile('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $result = $this->detect();

        $this->assertSame(Strategies::VITE, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('pnpm run build-only', $result['build_command']);
    }

    /** glowing-bear: `start` is `webpack serve`, and the site is what `build` writes. */
    private function writeWebpackSpa(array $extraDeps = [], ?string $webpackConfig = null): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'glowing-bear',
            'private' => true,
            'devDependencies' => ['webpack' => '^5.94.0', 'webpack-cli' => '^5.1.4', 'webpack-dev-server' => '^5.2.1'],
            'dependencies' => ['angular' => '^1.8.3'] + $extraDeps,
            'scripts' => ['build' => 'webpack', 'prestart' => 'npm install', 'start' => 'webpack serve'],
        ]));
        $this->writeFile('webpack.config.js', $webpackConfig ?? "const path = require('path');\nmodule.exports = {\n"
            . "    context: path.resolve(__dirname, 'src'),\n    output: {\n        path: path.resolve(__dirname, 'build'),\n    },\n"
            . "    devServer: { static: { directory: path.resolve(__dirname, 'build') } },\n};\n");
    }

    public function test_a_webpack_dev_server_start_is_a_static_build(): void
    {
        $this->writeWebpackSpa();

        $result = $this->detect();

        $this->assertSame('bundler-spa', $result['platform']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('build', $result['output_directory']);
        $this->assertSame('npm run build', $result['build_command']);
        $this->assertSame('', $result['start_command']);
    }

    public function test_a_webpack_dev_server_start_with_express_stays_a_node_server(): void
    {
        $this->writeWebpackSpa(['express' => '^4.21.0']);

        $result = $this->detect();

        $this->assertSame('express', $result['platform']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
        $this->assertSame('npm start', $result['start_command']);
    }

    public function test_a_webpack_dev_server_start_with_koa_stays_the_node_platform(): void
    {
        $this->writeWebpackSpa(['koa' => '^2.15.0']);

        $this->assertSame('node', $this->detect()['platform']);
    }

    public function test_an_unreadable_webpack_output_path_uses_the_default(): void
    {
        $this->writeWebpackSpa([], "module.exports = { output: { path: outDir(), filename: '[name].js' } };\n");

        $result = $this->detect();

        $this->assertSame('bundler-spa', $result['platform']);
        $this->assertSame('dist', $result['output_directory']);
    }

    public function test_cra_with_a_dev_server_start_is_still_cra(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['react' => '18.3.0', 'react-scripts' => '5.0.1'],
            'scripts' => ['start' => 'react-scripts start', 'build' => 'react-scripts build'],
        ]));

        $result = $this->detect();

        $this->assertSame('cra', $result['platform']);
        $this->assertSame('build', $result['output_directory']);
    }

    public function test_published_sail_compose_and_runtime_uses_laravel_recipe(): void
    {
        $this->writeFile(
            'compose.yaml',
            <<<'YAML'
services:
  laravel.test:
    build:
      context: ./docker/8.5
      dockerfile: Dockerfile
      args:
        WWWGROUP: '${WWWGROUP}'
    image: sail-8.5/app
    environment:
      WWWUSER: '${WWWUSER}'
      LARAVEL_SAIL: 1
    volumes:
      - '.:/var/www/html'
  mysql:
    image: 'mysql:8.0'
    environment:
      MYSQL_ROOT_PASSWORD: 'secret'
      MYSQL_DATABASE: 'laravel'
YAML
        );
        $this->writeFile(
            'docker/8.5/Dockerfile',
            "FROM ubuntu:24.04\nRUN groupadd --force -g \$WWWGROUP sail\n"
        );
        $this->writeFile('artisan', "#!/usr/bin/env php\n");
        $this->writeFile('composer.json', json_encode([
            'require' => ['laravel/framework' => '^12.0'],
        ]));

        $result = $this->detect();

        $this->assertSame(Strategies::LARAVEL, $result['strategy']);
        $this->assertSame('Laravel', $result['label']);
        $this->assertSame(8000, $result['port_hint']);
    }

    public function test_local_dev_compose_does_not_hide_later_production_compose(): void
    {
        $this->writeFile(
            'compose.yaml',
            "services:\n  app:\n    build: .\n    volumes:\n      - .:/app\n"
        );
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    image: nginx\n");

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
        $this->assertSame($this->tmpDir . '/docker-compose.yml', $result['compose_path']);
    }

    public function test_local_dev_compose_bind_and_build_falls_through_to_node_recipe(): void
    {
        $this->writeFile(
            'compose.yaml',
            "services:\n  app:\n    build: .\n    volumes:\n      - .:/app\n    ports:\n      - '3000:3000'\n"
        );
        $this->writeFile('package.json', json_encode(['dependencies' => ['express' => '4.21.0']]));
        $this->writeFile('index.js', 'require("express")');

        $result = $this->detect();

        $this->assertSame(Strategies::EXPRESS, $result['strategy']);
    }

    public function test_image_only_compose_with_bind_mount_stays_compose(): void
    {
        $this->writeFile(
            'docker-compose.yml',
            "services:\n  n8n:\n    image: n8nio/n8n\n    volumes:\n      - .:/home/src_yankee/.n8n\n    ports:\n      - '5678:5678'\n"
        );

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
    }

    public function test_production_build_compose_without_bind_stays_compose(): void
    {
        $this->writeFile(
            'compose.yaml',
            <<<'YAML'
services:
  app:
    build:
      context: .
      args:
        UID: '${UID:-1000}'
YAML
        );
        $this->writeFile('Dockerfile', "FROM node:20\nEXPOSE 3000\n");

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
    }

    public function test_host_uid_nested_dockerfile_does_not_win_over_php(): void
    {
        $this->writeFile(
            'docker/Dockerfile',
            "FROM ubuntu:24.04\nARG WWWGROUP\nRUN groupadd --force -g \$WWWGROUP app\n"
        );
        $this->writeFile('composer.json', json_encode([
            'require' => ['php' => '^8.2'],
        ]));

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
    }

    public function test_laravel_with_vite_and_sail_compose_uses_laravel_recipe(): void
    {
        $this->writeFile(
            'docker-compose.yml',
            "services:\n  laravel.test:\n    build:\n      context: ./vendor/laravel/sail/runtimes/8.4\n      dockerfile: Dockerfile\n    ports:\n      - '80:80'\n"
        );
        $this->writeFile('artisan', "#!/usr/bin/env php\n");
        $this->writeFile('composer.json', json_encode([
            'require' => ['laravel/framework' => '^11.0'],
        ]));
        $this->writeFile('package.json', json_encode([
            'devDependencies' => [
                'vite' => '6.2.0',
                'laravel-vite-plugin' => '1.2.0',
            ],
            'scripts' => ['build' => 'vite build && vite build --ssr'],
        ]));
        $this->writeFile('vite.config.js', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::LARAVEL, $result['strategy']);
        $this->assertSame('Laravel', $result['label']);
        $this->assertSame(8000, $result['port_hint']);
    }

    public function test_nested_scripts_docker_dockerfile_wins_over_laravel_vite(): void
    {
        $this->writeFile(
            'docker-compose.yml',
            "services:\n  laravel.test:\n    build:\n      context: ./vendor/laravel/sail/runtimes/8.4\n      dockerfile: Dockerfile\n"
        );
        $this->writeFile('artisan', "#!/usr/bin/env php\n");
        $this->writeFile('composer.json', json_encode([
            'require' => ['laravel/framework' => '^12.0'],
        ]));
        $this->writeFile('package.json', json_encode([
            'devDependencies' => [
                'vite' => '6.2.0',
                'laravel-vite-plugin' => '1.2.0',
            ],
        ]));
        $this->writeFile('vite.config.js', 'export default {};');
        $this->writeFile('scripts/docker/Dockerfile', "FROM php:8.3-apache\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
        $this->assertSame('scripts/docker/Dockerfile', $result['dockerfile']);
    }

    public function test_docker_dir_dockerfile_wins_over_railpack(): void
    {
        $this->writeFile('composer.json', '{"name":"app/app"}');
        $this->writeFile('docker/Dockerfile', "FROM php:8.3-cli\nEXPOSE 8080\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
        $this->assertSame('docker/Dockerfile', $result['dockerfile']);
        $this->assertSame(8080, $result['port_hint']);
    }

    public function test_nuxt_sveltekit_nestjs_express_and_cra(): void
    {
        $cases = [
            [
                'files' => [
                    'package.json' => json_encode(['dependencies' => ['nuxt' => '3.0.0']]),
                    'nuxt.config.ts' => 'export default {};',
                ],
                'id' => Strategies::NUXT,
                'runtime' => PlatformManifest::RUNTIME_NODE,
            ],
            [
                'files' => [
                    'package.json' => json_encode([
                        'dependencies' => ['svelte' => '5.0.0', '@sveltejs/kit' => '2.0.0'],
                    ]),
                    'svelte.config.js' => 'export default {};',
                ],
                'id' => Strategies::SVELTEKIT,
                'runtime' => PlatformManifest::RUNTIME_NODE,
            ],
            [
                'files' => [
                    'package.json' => json_encode(['dependencies' => ['@nestjs/core' => '10.0.0']]),
                    'nest-cli.json' => '{}',
                ],
                'id' => Strategies::NESTJS,
                'runtime' => PlatformManifest::RUNTIME_NODE,
            ],
            [
                'files' => [
                    'package.json' => json_encode(['dependencies' => ['express' => '4.0.0']]),
                    'index.js' => 'require("express")',
                ],
                'id' => Strategies::EXPRESS,
                'runtime' => PlatformManifest::RUNTIME_NODE,
            ],
            [
                'files' => [
                    'package.json' => json_encode(['dependencies' => ['@remix-run/react' => '2.0.0']]),
                ],
                'id' => Strategies::REMIX,
                'runtime' => PlatformManifest::RUNTIME_NODE,
            ],
            [
                'files' => [
                    'package.json' => json_encode(['dependencies' => ['react-scripts' => '5.0.0']]),
                ],
                'id' => Strategies::CRA,
                'runtime' => PlatformManifest::RUNTIME_NGINX,
            ],
        ];

        foreach ($cases as $case) {
            $this->removeDir($this->tmpDir);
            mkdir($this->tmpDir, 0777, true);
            foreach ($case['files'] as $name => $contents) {
                $this->writeFile($name, $contents);
            }
            $result = $this->detect();
            $this->assertSame($case['id'], $result['strategy'], $case['id']);
            $this->assertSame($case['runtime'], $result['runtime'], $case['id']);
        }
    }

    public function test_sveltekit_adapter_static_uses_nginx(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => [
                'svelte' => '5.0.0',
                '@sveltejs/kit' => '2.0.0',
                '@sveltejs/adapter-static' => '3.0.0',
            ],
        ]));
        $this->writeFile('svelte.config.js', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::SVELTEKIT, $result['strategy']);
        $this->assertSame('SvelteKit (static)', $result['label']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('build', $result['output_directory']);
    }

    public function test_generated_panelalpha_dockerfile_does_not_steal_framework_detect(): void
    {
        $this->writeFile('package.json', json_encode(['dependencies' => ['next' => '15.0.0']]));
        $this->writeFile('next.config.mjs', 'export default {};');
        $this->writeFile('panelalpha.Dockerfile', "FROM node:22\nEXPOSE 9\n");

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertNull($result['dockerfile']);
    }

    public function test_user_dockerfile_still_wins_over_nextjs(): void
    {
        $this->writeFile('Dockerfile', "FROM node:20\nEXPOSE 3000\n");
        $this->writeFile('package.json', json_encode(['dependencies' => ['next' => '15.0.0']]));
        $this->writeFile('next.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
    }

    public function test_pnpm_lockfile_selects_pnpm_install(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $this->writeFile('next.config.mjs', 'export default {};');

        $result = $this->detect();

        $this->assertSame('pnpm', $result['package_manager']);
        $this->assertStringContainsString('corepack prepare pnpm@10 --activate', $result['install_command']);
        $this->assertStringContainsString('HUSKY=0 LEFTHOOK=0 CI=1', $result['install_command']);
        $this->assertStringContainsString('pnpm install --frozen-lockfile --dangerously-allow-all-builds', $result['install_command']);
        $this->assertStringNotContainsString('pnpm-workspace.yaml', $result['install_command']);
        $this->assertSame('pnpm start', $result['start_command']);
        $this->assertSame('pnpm run build', $result['build_command']);
    }

    public function test_angular_reads_output_path_from_angular_json(): void
    {
        $this->writeFile('package.json', json_encode(['dependencies' => ['@angular/core' => '19.0.0']]));
        $this->writeFile('angular.json', json_encode([
            'projects' => [
                'web' => [
                    'architect' => [
                        'build' => [
                            'options' => [
                                'outputPath' => 'dist/web/browser',
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $result = $this->detect();

        $this->assertSame(Strategies::ANGULAR, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
        $this->assertSame('dist/web/browser', $result['output_directory']);
    }

    public function test_php_from_composer_json_without_artisan(): void
    {
        $this->writeFile('composer.json', '{"name":"app/app"}');

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
        $this->assertSame('PHP', $result['label']);
        $this->assertSame(8000, $result['port_hint']);
    }

    public function test_laravel_artisan_without_js_is_not_railpack(): void
    {
        $this->writeFile('artisan', "#!/usr/bin/env php\n");
        $this->writeFile('composer.json', json_encode([
            'require' => ['php' => '^8.3', 'laravel/framework' => '^13.0'],
        ]));

        $result = $this->detect();

        $this->assertSame(Strategies::LARAVEL, $result['strategy']);
        $this->assertSame(8000, $result['port_hint']);
    }

    public function test_go_mod_is_language_recipe_not_railpack(): void
    {
        $this->writeFile('go.mod', "module example.com/app\n\ngo 1.22\n");

        $result = $this->detect();

        $this->assertSame(Strategies::GO, $result['strategy']);
        $this->assertSame(GoRuntime::IMAGE, $result['image']);
        $this->assertSame(8080, $result['port_hint']);
    }

    /**
     * The generated script, not a one-line stub. Detection reads it now: a
     * file called `manage.py` is only evidence when it says it is Django's, or
     * when the project is laid out like a Django project.
     */
    public function test_django_manage_py_is_language_recipe(): void
    {
        $this->writeFile('manage.py', "#!/usr/bin/env python\nimport os\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'proj.settings')\n");
        $this->writeFile('requirements.txt', "django\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DJANGO, $result['strategy']);
        $this->assertSame(8000, $result['port_hint']);
        $this->assertStringContainsString('manage.py runserver', $result['start_command']);
    }

    /**
     * `django-admin startproject` run inside a subdirectory is one of Django's
     * two standard layouts — NetBox ships `netbox/manage.py`. Detection used to
     * ask for a `manage.py` at the repository root only, so these projects fell
     * through to the generic `python` platform, which has no `migrate` and no
     * `collectstatic`: the site came up against an unmigrated database with no
     * static files, answered 200, and was reported healthy.
     */
    public function test_django_below_the_root_is_still_django(): void
    {
        $this->writeFile('requirements.txt', "django\ngunicorn\n");
        $this->writeFile('netbox/manage.py', "#!/usr/bin/env python\n");
        $this->writeFile('netbox/netbox/__init__.py', '');
        $this->writeFile('netbox/netbox/wsgi.py', "application = get_wsgi_application()\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DJANGO, $result['strategy']);
        // Addressed by path from the root: the virtualenv lives there, so a
        // `cd netbox` would put `.venv/bin/python` out of reach.
        $this->assertStringContainsString('netbox/manage.py migrate', $result['resolved_commands']['migrate']);
        $this->assertStringContainsString(
            'netbox/manage.py collectstatic',
            $result['resolved_commands']['collectstatic']
        );
        // A declared server beats `runserver`, run with the project on the
        // import path exactly as NetBox's own systemd unit spells it.
        $this->assertStringContainsString("--pythonpath 'netbox' netbox.wsgi:application", $result['start_command']);
    }

    public function test_static_index_html(): void
    {
        $this->writeFile('index.html', '<h1>hi</h1>');

        $result = $this->detect();

        $this->assertSame(Strategies::STATIC, $result['strategy']);
        $this->assertSame('Static', $result['label']);
        $this->assertSame(8080, $result['port_hint']);
        $this->assertSame('index.html', $result['static_index']);
    }

    /**
     * The shape a hand-written site actually arrives in. Nothing claimed it
     * until html.yaml: Railpack builds nothing out of HTML, so it reached the
     * fallback recipe and was buried under the engine's own placeholder while
     * every page it needed was already on disk.
     */
    public function test_a_site_of_pages_with_no_index_is_the_html_platform(): void
    {
        $this->writeFile('home.html', '<h1>home</h1>');
        $this->writeFile('about.html', '<h1>about</h1>');
        $this->writeFile('contact.html', '<h1>contact</h1>');
        $this->writeFile('css/site.css', 'body{}');

        $result = $this->detect();

        $this->assertSame(Strategies::STATIC, $result['strategy']);
        $this->assertSame('html', $result['platform']);
        $this->assertSame('HTML site', $result['label']);
        $this->assertSame(8080, $result['port_hint']);
        $this->assertSame('home.html', $result['static_index']);
        DeployabilityCheck::assert($result, $this->tmpDir);
    }

    /** A site that names its own index is still `static`, and reads as it did. */
    public function test_an_index_html_still_belongs_to_the_static_platform(): void
    {
        $this->writeFile('index.html', '<h1>hi</h1>');
        $this->writeFile('about.html', '<h1>about</h1>');

        $result = $this->detect();

        $this->assertSame('static', $result['platform']);
        $this->assertSame('Static', $result['label']);
    }

    public function test_a_site_kept_in_a_subdirectory_is_claimed_and_its_entry_reported(): void
    {
        $this->writeFile('dist/index.html', '<h1>hi</h1>');
        $this->writeFile('dist/app.css', 'body{}');

        $result = $this->detect();

        $this->assertSame('html', $result['platform']);
        $this->assertSame('dist/index.html', $result['static_index']);
        DeployabilityCheck::assert($result, $this->tmpDir);
    }

    /**
     * The allowlist earning its keep. A tree holding a program is that
     * program's project, however much HTML is wrapped around it, and goes on
     * reaching the platform that knows how to run it.
     */
    public function test_html_beside_a_program_is_not_the_html_platform(): void
    {
        $this->writeFile('home.html', '<h1>home</h1>');
        $this->writeFile('mailer.php', "<?php mail();\n");

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
    }

    public function test_generated_bootstrap_compose_does_not_hide_named_html(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    image: nginx:alpine\n    ports:\n      - '8080:80'\n    volumes:\n      - './:/usr/share/nginx/html/:ro'\n");
        $this->writeFile('index.html', "<!DOCTYPE html><html><head><title>PanelAlpha — Ready</title></head><body><h2>Your environment is ready</h2></body></html>\n");
        $this->writeFile('plan_podrozy_toskania(1).html', "<!DOCTYPE html><html lang=\"pl\"><body><h1>Toskania</h1></body></html>\n");

        $result = $this->detect();

        $this->assertSame(Strategies::STATIC, $result['strategy']);
        $this->assertSame('plan_podrozy_toskania(1).html', $result['static_index']);
        DeployabilityCheck::assert($result, $this->tmpDir);
    }

    public function test_user_owned_compose_still_wins_over_html(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  n8n:\n    image: n8nio/n8n\n    ports:\n      - '5678:5678'\n");
        $this->writeFile('index.html', '<h1>ignore me</h1>');

        $result = $this->detect();

        $this->assertSame(Strategies::COMPOSE, $result['strategy']);
    }

    public function test_generated_compose_does_not_hide_dockerfile(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - './:/usr/share/nginx/html/:ro'\n");
        $this->writeFile('Dockerfile', "FROM node:22\nEXPOSE 3000\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
        $this->assertSame(3000, $result['port_hint']);
    }

    public function test_generated_framework_compose_does_not_hide_astro(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    build:\n      context: .\n      dockerfile: panelalpha.Dockerfile\n    ports:\n      - '8080:80'\n");
        $this->writeFile('panelalpha.Dockerfile', "FROM nginx:alpine\n");
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['astro' => '4.0.0'],
            'scripts' => ['start' => 'astro dev', 'build' => 'astro build'],
        ]));
        $this->writeFile('astro.config.mjs', "export default {};\n");

        $result = $this->detect();

        $this->assertSame(Strategies::ASTRO, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
    }

    public function test_unreadable_bootstrap_compose_does_not_hide_astro(): void
    {
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - './:/usr/share/nginx/html/:ro'\n    labels:\n      panelalpha.generated: static-bootstrap\n");
        $this->writeFile('index.html', "<!DOCTYPE html><html><head><title>PanelAlpha — Ready</title></head><body><h2>Your environment is ready</h2></body></html>\n");
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['astro' => '4.0.0'],
            'scripts' => ['start' => 'astro dev', 'build' => 'astro build'],
        ]));
        $this->writeFile('astro.config.mjs', "export default {};\n");
        chmod($this->tmpDir . '/docker-compose.yml', 0000);

        try {
            $result = $this->detect();
        } finally {
            @chmod($this->tmpDir . '/docker-compose.yml', 0644);
        }

        $this->assertSame(Strategies::ASTRO, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NGINX, $result['runtime']);
    }

    public function test_fallback_when_only_readme(): void
    {
        $this->writeFile('README.md', '# nothing useful');

        $result = $this->detect();

        $this->assertSame(Strategies::FALLBACK, $result['strategy']);
        $this->assertSame('Unknown', $result['label']);
    }

    public function test_vscode_extension_is_not_railpack_http_app(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'little-oxford',
            'main' => './dist/extension.js',
            'engines' => ['vscode' => '^1.85.0'],
            'scripts' => ['build' => 'node esbuild.config.mjs'],
        ]));
        $this->writeFile('package-lock.json', '{}');

        $result = $this->detect();

        $this->assertTrue(EditorExtension::describes(
            json_decode((string) file_get_contents($this->tmpDir . '/package.json'), true) ?: []
        ));
        $this->assertSame(Strategies::FALLBACK, $result['strategy']);
        $this->assertSame('Not a web app', $result['label']);
        $this->assertSame(8080, $result['port_hint']);
    }

    /**
     * A Gemfile on its own says nothing about how to start the app, so it
     * still belongs to Railpack.
     */
    public function test_gemfile_alone_falls_through_to_railpack(): void
    {
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\ngem 'rails'\n");

        $result = $this->detect();

        $this->assertSame(Strategies::RAILPACK, $result['strategy']);
    }

    public function test_rack_app_is_the_ruby_recipe_not_railpack(): void
    {
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\ngem 'sinatra'\n");
        $this->writeFile('config.ru', "run Sinatra::Application\n");

        $result = $this->detect();

        $this->assertSame(Strategies::RUBY, $result['strategy']);
        $this->assertSame('Ruby', $result['label']);
        $this->assertSame(3000, $result['port_hint']);
    }

    public function test_procfile_ruby_app_is_the_ruby_recipe(): void
    {
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\n");
        $this->writeFile('Procfile', "web: bundle exec ruby server.rb\n");

        $result = $this->detect();

        $this->assertSame(Strategies::RUBY, $result['strategy']);
    }

    public function test_rails_still_reports_itself_as_rails(): void
    {
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\ngem 'rails'\n");
        $this->writeFile('config/application.rb', "module App; end\n");
        $this->writeFile('config.ru', "run App::Application\n");

        $result = $this->detect();

        $this->assertSame(Strategies::RAILS, $result['strategy']);
        $this->assertSame('Rails', $result['label']);
    }

    /**
     * A repo that ships its own Dockerfile keeps it, exactly as it does
     * against every other recipe.
     */
    public function test_explicit_dockerfile_still_wins_over_the_ruby_recipe(): void
    {
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\ngem 'sinatra'\n");
        $this->writeFile('config.ru', "run Sinatra::Application\n");
        $this->writeFile('Dockerfile', "FROM ruby:3.3\n");

        $result = $this->detect();

        $this->assertSame(Strategies::DOCKERFILE, $result['strategy']);
    }

    /**
     * Ruby is checked before PHP and the JS recipes, so a Rack app that also
     * carries a package.json for its frontend is still a Ruby app.
     */
    public function test_rack_app_with_a_package_json_is_still_ruby(): void
    {
        $this->writeFile('Gemfile', "source 'https://rubygems.org'\ngem 'sinatra'\n");
        $this->writeFile('config.ru', "run Sinatra::Application\n");
        $this->writeFile('package.json', '{"name":"assets","scripts":{"build":"vite build"}}');

        $result = $this->detect();

        $this->assertSame(Strategies::RUBY, $result['strategy']);
    }

    public function test_flask_app_py_is_python_recipe(): void
    {
        $this->writeFile('requirements.txt', "flask\n");
        $this->writeFile('app.py', "from flask import Flask\napp = Flask(__name__)\n");

        $result = $this->detect();

        $this->assertSame(Strategies::PYTHON, $result['strategy']);
        $this->assertSame('.venv/bin/python app.py', $result['start_command']);
        $this->assertSame(PythonRuntime::IMAGE, $result['image']);
    }

    public function test_next_config_mts_is_detected(): void
    {
        $this->writeFile('package.json', json_encode([
            'dependencies' => ['next' => '15.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]));
        $this->writeFile('next.config.mts', 'export default {};');

        $result = $this->detect();

        $this->assertSame(Strategies::NEXTJS, $result['strategy']);
        $this->assertSame(PlatformManifest::RUNTIME_NODE, $result['runtime']);
    }

    public function test_pyproject_requires_python_selects_image(): void
    {
        $this->writeFile('pyproject.toml', "[project]\nname = \"app\"\nrequires-python = \">=3.11\"\n");
        $this->writeFile('main.py', "print('hi')\n");

        $result = $this->detect();

        $this->assertSame(Strategies::PYTHON, $result['strategy']);
        $this->assertSame('python:3.11-slim', $result['image']);
        $this->assertSame('.venv/bin/python main.py', $result['start_command']);
    }

    /**
     * Dolibarr (Dolibarr/dolibarr @ develop) is a PHP application: `htdocs/`
     * and a `composer.json.disabled`, and a root pyproject.toml whose only
     * job is codespell configuration. `{"file": "pyproject.toml"}` matched the
     * name, so detection reported "Python", the start command fell through to
     * the placeholder, and the app was served the engine's own page.
     *
     * The excerpt below keeps the decisive part of the real file: a
     * `[build-system]` naming a Python backend and several `[tool.*]` tables,
     * with no `[project]` and no `[tool.poetry]`. That `[build-system]` is
     * why the predicate cannot simply accept a Python build backend.
     */
    public function test_a_tool_only_pyproject_does_not_make_a_php_project_python(): void
    {
        $this->writeFile('pyproject.toml', <<<'TOML'
        [build-system]
        requires = ["setuptools>=61.2"]
        build-backend = "setuptools.build_meta"

        [tool.codespell]
        skip = "*/langs/*"
        check-hidden = true

        [tool.setuptools]
        include-package-data = false

        [tool.sqlfluff.core]
        dialect = "mysql"
        TOML);
        $this->writeFile('htdocs/index.php', '<?php // Dolibarr front controller');

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
        $this->assertNotSame(Strategies::PYTHON, $result['strategy']);
    }

    /**
     * Composio (ComposioHQ/composio @ next) is the same false positive from
     * the other direction: its root pyproject.toml is a uv workspace that
     * lists packages in `members/`, declares no project of its own, and has
     * no `[build-system]` at all.
     */
    public function test_a_uv_workspace_root_pyproject_does_not_make_it_python(): void
    {
        $this->writeFile('pyproject.toml', <<<'TOML'
        [tool.uv.workspace]
        members = ["python"]

        [dependency-groups]
        dev = ["composio==1.0.0rc6"]
        TOML);
        $this->writeFile('main.py', "print('hi')\n");

        $result = $this->detect();

        $this->assertNotSame(Strategies::PYTHON, $result['strategy']);
    }

    /**
     * The static/html manifests keep `pyproject.toml` in their `none` guard so
     * a Python project is not served as documents. That guard has to use the
     * same predicate as python.yaml, or a directory of HTML with a tool-only
     * pyproject beside it would be refused by every platform in the chain.
     */
    public function test_a_static_site_with_a_tool_only_pyproject_is_still_static(): void
    {
        $this->writeFile('pyproject.toml', "[tool.ruff]\nline-length = 88\n");
        $this->writeFile('index.html', '<h1>hello</h1>');

        $result = $this->detect();

        $this->assertSame(Strategies::STATIC, $result['strategy']);
    }

    /** A real pyproject still claims Python, so the guard above still bites. */
    public function test_a_static_site_with_a_real_pyproject_is_not_static(): void
    {
        $this->writeFile('pyproject.toml', "[project]\nname = \"app\"\n");
        $this->writeFile('index.html', '<h1>hello</h1>');

        $this->assertSame(Strategies::PYTHON, $this->detect()['strategy']);
    }

    public function test_assert_deployable_rejects_empty_directory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');

        DeployabilityCheck::assert(
            $this->detect(),
            $this->tmpDir
        );
    }

    public function test_assert_deployable_accepts_static_index(): void
    {
        $this->writeFile('index.html', '<h1>hi</h1>');
        $decision = $this->detect();

        DeployabilityCheck::assert($decision, $this->tmpDir);

        $this->assertSame(Strategies::STATIC, $decision['strategy']);
    }

    public function test_extract_paemd_compose(): void
    {
        $content = "- `compose.yml`\n```yaml\nservices:\n  web:\n    image: nginx\n```\n";

        $this->assertSame(
            "services:\n  web:\n    image: nginx",
            PaemdPage::compose($content)
        );
    }

    public function test_expose_port_ignores_comments(): void
    {
        $this->writeFile('Dockerfile', "# EXPOSE 99\nFROM nginx\nEXPOSE 8081/tcp\n");

        $this->assertSame(
            8081,
            DockerfileFinder::exposedPort($this->tmpDir . '/Dockerfile')
        );
    }

    public function test_plain_php_without_composer_json_is_php(): void
    {
        $this->writeFile('index.php', "<?php echo 'hello';\n");

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
        $this->assertSame('php-plain', $result['platform']);
    }

    /**
     * The case that sent three deploys to a placeholder page: the dind template
     * seeds its own index.html, and StaticEntry serves the engine's placeholder
     * as a last resort, so a site whose only other file was index.php deployed
     * as a static document and never ran PHP.
     */
    public function test_php_wins_over_the_engine_placeholder_index(): void
    {
        $this->writeFile('index.html', '<!doctype html><title>' . PlaceholderPage::WELCOME_TITLE . '</title>');
        $this->writeFile('index.php', "<?php phpinfo();\n");

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
        $this->assertSame('php-plain', $result['platform']);
    }

    public function test_php_two_directories_down_is_still_php(): void
    {
        $this->writeFile('src/app/index.php', "<?php echo 1;\n");

        $this->assertSame(Strategies::PHP, $this->detect()['strategy']);
    }

    public function test_php_deeper_than_two_directories_is_not_php(): void
    {
        $this->writeFile('one/two/three/index.php', "<?php echo 1;\n");

        $this->assertNotSame('php-plain', $this->detect()['platform'] ?? null);
    }

    public function test_vendored_php_alone_does_not_make_a_php_project(): void
    {
        $this->writeFile('vendor/acme/lib/Thing.php', "<?php class Thing {}\n");
        $this->writeFile('README.md', 'nothing else here');

        $this->assertNotSame('php-plain', $this->detect()['platform'] ?? null);
    }

    public function test_a_composer_project_still_uses_the_php_platform(): void
    {
        $this->writeFile('composer.json', '{"name":"app/app"}');
        $this->writeFile('index.php', "<?php echo 1;\n");

        $result = $this->detect();

        $this->assertSame(Strategies::PHP, $result['strategy']);
        $this->assertSame('php', $result['platform']);
    }

    public function test_a_static_site_with_no_php_is_still_static(): void
    {
        $this->writeFile('index.html', '<!doctype html><title>a real site</title><h1>hi</h1>');

        $this->assertSame(Strategies::STATIC, $this->detect()['strategy']);
    }

    public function test_a_node_project_with_a_stray_php_file_is_not_php(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'app',
            'dependencies' => ['express' => '^4.18.0'],
            'scripts' => ['start' => 'node server.js'],
        ]));
        $this->writeFile('server.js', "require('express')\n");
        $this->writeFile('legacy/old.php', "<?php echo 1;\n");

        $this->assertNotSame(Strategies::PHP, $this->detect()['strategy']);
    }

    /**
     * php-plain carries no composer.json by definition, and the root-file rule
     * that guards the PHP strategy must not reject it -- it is skipped for a
     * manifest that named the project itself, the same exemption osTicket
     * relies on.
     */
    public function test_plain_php_passes_the_deployability_check(): void
    {
        $this->writeFile('index.php', "<?php echo 1;\n");

        DeployabilityCheck::assert($this->detect(), $this->tmpDir);

        $this->assertTrue(true, 'a php-plain project needs no composer.json');
    }

    // -------------------------------------------------------------------------
    // The Node platform: a package.json with no framework in it.
    //
    // Node was the one runtime the engine recognises with no platform of its
    // own, so a plain project of its kind fell to Railpack and was built by
    // mise inside a builder image rather than run on the Node image already on
    // the host. These pin both halves of fixing that: it claims a plain
    // project, and it claims nothing that belongs to someone else.
    // -------------------------------------------------------------------------

    public function test_a_plain_node_project_resolves_to_the_node_runtime(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'app',
            'scripts' => ['start' => 'node server.js'],
        ]));
        $this->writeFile('server.js', '');

        $decision = $this->detect();

        $this->assertSame('node', $decision['platform']);
        $this->assertSame('node', $decision['runtime']);
        $this->assertNotSame(Strategies::RAILPACK, $decision['strategy']);
    }

    /**
     * No start script, but an entry file is enough: `node index.js` is what
     * anyone would run, and it is what the platform falls back to.
     */
    public function test_an_entry_file_is_enough_without_a_start_script(): void
    {
        $this->writeFile('package.json', json_encode(['name' => 'app']));
        $this->writeFile('index.js', '');

        $this->assertSame('node', $this->detect()['platform']);
    }

    /**
     * Neither a start script nor an entry file: a library or a monorepo root.
     * Guessing an entry point produces a container that builds and then exits,
     * which reads as a broken deploy rather than as something that was never a
     * server — so this is left to the fallback.
     */
    public function test_a_library_with_no_entry_point_is_not_claimed(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'lib',
            'main' => 'lib/index.js',
        ]));
        $this->writeFile('lib/index.js', '');

        $this->assertNotSame('node', $this->detect()['platform'] ?? null);
    }

    /**
     * package.json is the weakest signal there is — Rails carries one for the
     * asset pipeline, Django for a frontend, Laravel for Vite. Whoever
     * declares a stronger runtime is that runtime, and Node is its build tool.
     *
     * @param array<string, string> $files
     */
    #[DataProvider('projectsThatOwnTheirPackageJson')]
    public function test_node_does_not_claim_a_project_that_declares_another_runtime(
        array $files,
        string $expected
    ): void {
        $this->writeFile('package.json', json_encode([
            'name' => 'app',
            'scripts' => ['start' => 'node index.js'],
        ]));
        $this->writeFile('index.js', '');
        foreach ($files as $path => $contents) {
            $this->writeFile($path, $contents);
        }

        $this->assertSame($expected, $this->detect()['platform']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function projectsThatOwnTheirPackageJson(): array
    {
        return [
            'express' => [['package.json' => '{"name":"a","dependencies":{"express":"^4"},"scripts":{"start":"node index.js"}}'], 'express'],
            'django' => [
                ['manage.py' => "import os\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'proj.settings')\n", 'requirements.txt' => "django\n"],
                'django',
            ],
            'rails' => [['Gemfile' => "source 'https://rubygems.org'\n", 'config/application.rb' => ''], 'rails'],
            'laravel' => [['composer.json' => '{"require":{"laravel/framework":"^11"}}', 'artisan' => ''], 'laravel'],
        ];
    }

    /**
     * An editor extension is an ordinary npm package with an ordinary
     * package.json, so every file-based rule says "Node app". It builds
     * cleanly and then crashes on `require('vscode')`.
     */
    public function test_an_editor_extension_is_still_not_a_web_app(): void
    {
        $this->writeFile('package.json', json_encode([
            'name' => 'ext',
            'engines' => ['vscode' => '^1.80.0'],
            'contributes' => ['commands' => []],
            'scripts' => ['start' => 'node index.js'],
        ]));
        $this->writeFile('index.js', '');

        $this->assertSame('not-a-web-app', $this->detect()['platform']);
    }

    private function writePlaceholder(string $title): void
    {
        $this->writeFile('index.html', "<!doctype html><title>{$title}</title><h1>placeholder</h1>");
    }

    public function test_the_placeholder_stands_while_the_project_is_only_scaffolding(): void
    {
        $this->writePlaceholder(PlaceholderPage::WELCOME_TITLE);
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    labels:\n      " . GeneratedCompose::LABEL . ": static-bootstrap\n");
        $this->writeFile('panelalpha-entrypoint.sh', "#!/bin/sh\n");

        $this->assertNull(PlaceholderPage::supersededIndex($this->tmpDir));
    }

    public function test_the_placeholder_is_superseded_by_the_users_own_file(): void
    {
        $this->writePlaceholder(PlaceholderPage::WELCOME_TITLE);
        $this->writeFile('index.php', "<?php echo 1;\n");

        $this->assertSame(
            $this->tmpDir . '/index.html',
            PlaceholderPage::supersededIndex($this->tmpDir)
        );
    }

    public function test_a_compose_file_the_user_wrote_supersedes_the_placeholder(): void
    {
        $this->writePlaceholder(PlaceholderPage::WELCOME_TITLE);
        $this->writeFile('docker-compose.yml', "services:\n  app:\n    image: nginx\n");

        $this->assertNotNull(PlaceholderPage::supersededIndex($this->tmpDir));
    }

    public function test_a_real_index_html_is_never_treated_as_a_placeholder(): void
    {
        $this->writeFile('index.html', '<!doctype html><title>my site</title>');
        $this->writeFile('index.php', "<?php echo 1;\n");

        $this->assertNull(PlaceholderPage::supersededIndex($this->tmpDir));
    }
}
