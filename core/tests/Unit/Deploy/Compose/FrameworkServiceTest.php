<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\FrameworkService;
use App\Lib\Deploy\Compose\GeneratedCompose;
use PHPUnit\Framework\TestCase;

/**
 * The one service a generated compose file describes, in whichever of three
 * shapes the recipe asked for.
 *
 * A framework build ends up as nginx over a directory, as Node running a
 * generated server script, or as an image the engine builds - and the choice
 * is not cosmetic: serving a Nuxt SSR build as static files returns 404 for
 * every route, and building a Vite site as a Node app runs a dev server in
 * production.
 */
class FrameworkServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private function service(array $decision, int $port = 3000, ?string $publicUrl = null): array
    {
        return FrameworkService::for($decision, $port, $publicUrl);
    }

    public function test_every_shape_is_labelled_as_the_engines_own(): void
    {
        // The label is how the next deploy knows this file was generated
        // rather than shipped by the project.
        foreach ([['runtime' => 'nginx'], ['strategy' => 'nuxt'], []] as $decision) {
            $service = $this->service($decision);

            $this->assertSame('framework-recipe', $service['labels'][GeneratedCompose::LABEL]);
            $this->assertSame('unless-stopped', $service['restart']);
        }
    }

    public function test_a_static_build_is_served_by_nginx(): void
    {
        $service = $this->service(['runtime' => 'nginx', 'output_directory' => 'dist']);

        $this->assertSame('nginx:alpine', $service['image']);
        $this->assertSame(['8080:80'], $service['ports']);
        $this->assertContains('./dist:/usr/share/nginx/html:ro', $service['volumes']);
        $this->assertContains(
            './panelalpha.nginx.conf:/etc/nginx/conf.d/default.conf:ro',
            $service['volumes']
        );
    }

    public function test_a_static_build_mounts_its_own_output_directory(): void
    {
        $service = $this->service(['runtime' => 'nginx', 'output_directory' => 'dist/my-app/browser']);

        $this->assertContains('./dist/my-app/browser:/usr/share/nginx/html:ro', $service['volumes']);
    }

    public function test_an_output_directory_cannot_escape_the_project(): void
    {
        // The value reaches here from a project's own angular.json.
        $service = $this->service(['runtime' => 'nginx', 'output_directory' => '../../etc']);

        $this->assertContains('./dist:/usr/share/nginx/html:ro', $service['volumes']);
    }

    public function test_a_static_site_is_served_read_only(): void
    {
        $service = $this->service(['runtime' => 'nginx']);

        foreach ($service['volumes'] as $volume) {
            $this->assertStringEndsWith(':ro', $volume);
        }
    }

    public function test_a_static_site_needs_no_runtime_environment(): void
    {
        // nginx reads no PORT and no .env, and mounting the project's .env
        // into it would put secrets in a container that serves files.
        $service = $this->service(['runtime' => 'nginx']);

        $this->assertArrayNotHasKey('environment', $service);
        $this->assertArrayNotHasKey('env_file', $service);
    }

    public function test_a_standalone_build_runs_the_generated_server_script(): void
    {
        // Nuxt and TanStack Start emit their own server bundle; there is
        // nothing left to build, only something to run.
        $service = $this->service(['strategy' => 'nuxt'], 3000);

        $this->assertSame('node:22-bookworm-slim', $service['image']);
        $this->assertSame('node panelalpha.serve.mjs', $service['command']);
        $this->assertSame('/app', $service['working_dir']);
        $this->assertSame(['3000:3000'], $service['ports']);
        $this->assertArrayNotHasKey('build', $service);
    }

    public function test_a_bun_project_is_started_by_bun(): void
    {
        // Mixing the two leaves bun-only packages unresolved at request time.
        $service = $this->service(['strategy' => 'nuxt', 'image' => 'oven/bun:1']);

        $this->assertSame('oven/bun:1', $service['image']);
        $this->assertSame('bun panelalpha.serve.mjs', $service['command']);
    }

    public function test_a_standalone_build_mounts_its_output_read_only(): void
    {
        $service = $this->service(['strategy' => 'tanstack-start']);

        $this->assertContains('./panelalpha.serve.mjs:/app/panelalpha.serve.mjs:ro', $service['volumes']);
        $this->assertContains('./node_modules:/app/node_modules:ro', $service['volumes']);
        foreach ($service['volumes'] as $volume) {
            $this->assertStringEndsWith(':ro', $volume);
        }
    }

    public function test_anything_else_is_built_from_the_generated_dockerfile(): void
    {
        $service = $this->service([], 8080);

        $this->assertSame(
            ['context' => '.', 'dockerfile' => 'panelalpha.Dockerfile'],
            $service['build']
        );
        $this->assertSame(['8080:8080'], $service['ports']);
        $this->assertArrayNotHasKey('image', $service);
    }

    public function test_a_running_service_is_told_where_to_listen(): void
    {
        // 0.0.0.0 rather than localhost: a server bound to loopback inside a
        // container is unreachable from the proxy.
        $service = $this->service([], 4000);

        $this->assertSame('0.0.0.0', $service['environment']['HOST']);
        $this->assertSame('0.0.0.0', $service['environment']['HOSTNAME']);
        $this->assertSame('4000', $service['environment']['PORT']);
        $this->assertSame(['.env'], $service['env_file']);
    }

    public function test_the_public_url_reaches_the_application(): void
    {
        $service = $this->service([], 3000, 'https://shop.example.com');

        $this->assertSame('https://shop.example.com', $service['environment']['APP_URL']);
        $this->assertSame('on', $service['environment']['HTTPS']);
    }

    /** DVinyl-shaped: `BASE_URL=` blank in .env.example means "serve from /". */
    public function test_a_path_prefix_key_the_project_sets_is_left_to_its_env_file(): void
    {
        $service = $this->service(['path_prefix_keys' => ['BASE_URL']], 3000, 'https://shop.example.com');

        $this->assertArrayNotHasKey('BASE_URL', $service['environment']);
        $this->assertSame('https://shop.example.com', $service['environment']['APP_URL']);
        $this->assertSame(['.env'], $service['env_file']);

        // A recipe or the account that names it still wins.
        $service = $this->service(
            ['path_prefix_keys' => ['BASE_URL'], 'env' => ['BASE_URL' => '/app']],
            3000,
            'https://shop.example.com'
        );
        $this->assertSame('/app', $service['environment']['BASE_URL']);
    }

    public function test_the_recipes_own_variables_win(): void
    {
        // A recipe that says which port its framework listens on knows better
        // than the generic default.
        $service = $this->service(['env' => ['PORT' => '5000', 'NITRO_PRESET' => 'node-server']], 3000);

        $this->assertSame('5000', $service['environment']['PORT']);
        $this->assertSame('node-server', $service['environment']['NITRO_PRESET']);
    }

    public function test_declared_dependencies_are_carried_over(): void
    {
        $service = $this->service(['depends_on' => ['db', 'redis']]);

        $this->assertSame(['db', 'redis'], $service['depends_on']);
    }

    public function test_a_service_that_depends_on_nothing_declares_nothing(): void
    {
        $this->assertArrayNotHasKey('depends_on', $this->service([]));
        $this->assertArrayNotHasKey('depends_on', $this->service(['depends_on' => []]));
    }

    /**
     * PHP is served from the shared base image with the account's own
     * directory mounted, so there is nothing for compose to build.
     */
    public function test_php_runs_the_shared_image_with_the_project_mounted(): void
    {
        $service = FrameworkService::for([
            'runtime' => 'php',
            'image' => 'panelalpha/php:8.3-apache-bookworm-pa9519f31e',
        ], 8000);

        $this->assertSame('panelalpha/php:8.3-apache-bookworm-pa9519f31e', $service['image']);
        $this->assertSame('/app', $service['working_dir']);
        $this->assertContains('./:/app', $service['volumes']);
        $this->assertArrayNotHasKey('build', $service);
    }

    /**
     * A repository whose application is not at its root declares `app_root`
     * in its recipe; the mount follows it, so the document root, the
     * entrypoint and the host build all still work on /app.
     */
    public function test_the_mount_follows_the_recipes_app_root(): void
    {
        $service = FrameworkService::for([
            'runtime' => 'php',
            'image' => 'panelalpha/php:8.2-apache-bookworm-pa9519f31e',
            'app_root' => 'phpBB',
        ], 8000);

        $this->assertContains('./phpBB:/app', $service['volumes']);
    }

    /**
     * `app_root` reaches this class as a bind-mount source. The schema already
     * refuses an absolute path; a value that could climb out of the checkout
     * falls back to the checkout rather than mounting it.
     */
    public function test_an_app_root_that_escapes_the_checkout_is_ignored(): void
    {
        foreach (['../../etc', '/etc', 'a/../../b', '$(id)'] as $hostile) {
            $service = FrameworkService::for([
                'runtime' => 'php',
                'image' => 'panelalpha/php:8.3-apache-bookworm-pa9519f31e',
                'app_root' => $hostile,
            ], 8000);

            $this->assertContains('./:/app', $service['volumes'], "app_root {$hostile} was not refused");
        }
    }

    public function test_the_accounts_composer_cache_is_mounted_when_there_is_one(): void
    {
        $service = FrameworkService::for([
            'runtime' => 'php',
            'image' => 'panelalpha/php:8.3-apache-bookworm-pa9519f31e',
            'composer_cache_dir' => '/home/acme/.cache/composer',
        ], 8000);

        $this->assertContains('/home/acme/.cache/composer:/var/cache/pa-composer', $service['volumes']);
    }

    /**
     * The container is the hosting account, said once in compose.
     *
     * Not by dropping privileges inside the container: Docker creates the
     * container's stdio owned by this uid, which is what lets Apache open
     * `ErrorLog /dev/stderr`. A process that starts as root and drops keeps
     * root-owned stdio and Apache dies on AH00091.
     */
    public function test_php_runs_as_the_hosting_account(): void
    {
        $service = FrameworkService::for([
            'runtime' => 'php',
            'image' => 'panelalpha/php:8.3-apache-bookworm-paXXXX',
            'user' => '1001:1001',
        ], 8000);

        $this->assertSame('1001:1001', $service['user']);
    }

    /**
     * An account whose uid the engine could not read is a bug worth seeing as
     * a root-owned file, not one worth writing `user: ":"` into a compose file
     * that then refuses to start.
     */
    public function test_an_unknown_account_leaves_the_user_unset(): void
    {
        foreach ([[], ['user' => ''], ['user' => '   ']] as $decision) {
            $service = FrameworkService::for(
                ['runtime' => 'php', 'image' => 'panelalpha/php:8.3-apache-bookworm-paXXXX'] + $decision,
                8000
            );
            $this->assertArrayNotHasKey('user', $service);
        }
    }

    /**
     * PeerTube: pnpm through corepack in the host build, then `exec pnpm start`
     * in plain node:22-bookworm, which has no pnpm, and a 127 restart loop.
     */
    public function test_a_pnpm_project_gets_its_package_manager_in_the_run_container(): void
    {
        $install = "HUSKY=0 LEFTHOOK=0 CI=1 sh -c 'command -v corepack >/dev/null 2>&1 || npm install -g corepack'"
            . ' && corepack enable && corepack prepare pnpm@10.15.1 --activate && pnpm install --frozen-lockfile';
        $service = $this->service([
            'strategy' => 'express',
            'runtime' => 'node',
            'image' => 'node:22-bookworm',
            'package_manager' => 'pnpm',
            'install_command' => $install,
            'entrypoint' => 'panelalpha-entrypoint.sh',
        ]);

        $script = $service['command'][2];
        $this->assertStringContainsString('corepack enable --install-directory /tmp/corepack-bin', $script);
        $this->assertStringContainsString('corepack prepare pnpm@10.15.1 --activate', $script);
        $this->assertStringContainsString('PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$$PATH', $script);
        $this->assertStringEndsWith(' && exec /app/panelalpha-entrypoint.sh', $script);
        $this->assertStringNotContainsString('HUSKY', $script);

        $direct = $this->service([
            'strategy' => 'nextjs',
            'package_manager' => 'pnpm',
            'install_command' => $install,
            'start_command' => 'pnpm start',
        ]);
        $this->assertStringEndsWith(' && exec pnpm start', $direct['command'][2]);
        $this->assertStringContainsString('corepack prepare pnpm@10.15.1', $direct['command'][2]);
    }

    public function test_npm_and_yarn_classic_run_unchanged(): void
    {
        foreach ([
            ['npm', 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund'],
            ['yarn', "HUSKY=0 LEFTHOOK=0 CI=1 sh -c 'command -v corepack >/dev/null 2>&1 || npm install -g corepack'"
                . ' && corepack enable && yarn install --frozen-lockfile --ignore-engines'],
        ] as [$pm, $install]) {
            $service = $this->service([
                'strategy' => 'express',
                'package_manager' => $pm,
                'install_command' => $install,
                'entrypoint' => 'panelalpha-entrypoint.sh',
            ]);
            $this->assertSame(['sh', '-c', 'exec /app/panelalpha-entrypoint.sh'], $service['command'], $pm);
        }
    }

    public function test_yarn_berry_is_provisioned_too(): void
    {
        $service = $this->service([
            'strategy' => 'express',
            'package_manager' => 'yarn',
            'install_command' => "HUSKY=0 LEFTHOOK=0 CI=1 sh -c 'command -v corepack >/dev/null 2>&1 || npm install -g corepack'"
                . ' && corepack enable && yarn install --immutable',
            'entrypoint' => 'panelalpha-entrypoint.sh',
        ]);

        $this->assertStringContainsString('corepack enable --install-directory', $service['command'][2]);
    }

    /** The account uid gets a passwd entry from files written next to the run file. */
    public function test_php_mounts_the_account_user_files_read_only(): void
    {
        $service = FrameworkService::for([
            'runtime' => 'php',
            'image' => 'panelalpha/php:8.3-apache-bookworm-paXXXX',
            'user' => '1001:1001',
            'account_user_files' => true,
        ], 8000);

        $this->assertContains('./panelalpha.passwd:/etc/passwd:ro', $service['volumes']);
        $this->assertContains('./panelalpha.group:/etc/group:ro', $service['volumes']);
    }

    public function test_without_account_user_files_nothing_is_mounted_over_etc(): void
    {
        $service = FrameworkService::for([
            'runtime' => 'php',
            'image' => 'php:8.3-apache-bookworm',
            'user' => '1001:1001',
        ], 8000);

        foreach ($service['volumes'] as $volume) {
            $this->assertStringNotContainsString(':/etc/', $volume);
        }
    }
}
