<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DeployComposeTest extends TestCase
{
    public function test_static_compose_is_nginx_bind_mount_without_build(): void
    {
        $parsed = Yaml::parse(DeployCompose::staticNginx());

        $this->assertSame(Images::NGINX_IMAGE, $parsed['services']['app']['image']);
        $this->assertSame(['8080:80'], $parsed['services']['app']['ports']);
        $this->assertSame(
            [
                './:/usr/share/nginx/html/:ro',
                './' . NginxConfig::FILENAME . ':/etc/nginx/conf.d/default.conf:ro',
            ],
            $parsed['services']['app']['volumes']
        );
        $this->assertSame(
            DetectProjectStrategy::COMPOSE_GENERATED_LABEL,
            $parsed['services']['app']['labels']['panelalpha.generated']
        );
        $this->assertArrayNotHasKey('build', $parsed['services']['app']);
    }

    public function test_dockerfile_compose_builds_named_file(): void
    {
        $parsed = Yaml::parse(DeployCompose::dockerfile('Dockerfile.web', 3000));

        $this->assertSame('.', $parsed['services']['app']['build']['context']);
        $this->assertSame('Dockerfile.web', $parsed['services']['app']['build']['dockerfile']);
        $this->assertSame(['3000:3000'], $parsed['services']['app']['ports']);
        // No decision, so nothing said the app mounts anything — and a service
        // with an empty `volumes:` is not the same file as one without it.
        $this->assertArrayNotHasKey('volumes', $parsed['services']['app']);
    }

    public function test_dockerfile_root_uses_dot_build_and_maps_80_to_8080(): void
    {
        $parsed = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 80));

        $this->assertSame('.', $parsed['services']['app']['build']);
        $this->assertSame(['8080:80'], $parsed['services']['app']['ports']);
    }

    public function test_a_decision_without_cache_refs_leaves_the_build_untouched(): void
    {
        $parsed = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000));

        $this->assertSame('.', $parsed['services']['app']['build']);
    }

    /**
     * The app's nested Docker resolves none of the engine's compose names, so
     * a database on the account's own MySQL server is only reachable if the
     * deploy pins the name to the address it resolved.
     */
    public function test_framework_pins_hosts_the_nested_daemon_cannot_resolve(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            [
                'runtime' => PlatformManifest::RUNTIME_PHP,
                'extra_hosts' => ['database-users.shared-hosting.palocal:172.18.0.2'],
            ],
            8000
        ));

        $this->assertSame(
            ['database-users.shared-hosting.palocal:172.18.0.2'],
            $parsed['services']['app']['extra_hosts']
        );
    }

    public function test_framework_pins_nothing_when_there_is_nothing_to_pin(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            ['runtime' => PlatformManifest::RUNTIME_PHP],
            8000
        ));

        $this->assertArrayNotHasKey('extra_hosts', $parsed['services']['app']);
    }

    public function test_framework_nginx_runtime_has_no_node_env(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            ['runtime' => PlatformManifest::RUNTIME_NGINX, 'output_directory' => 'dist'],
            8080
        ));

        $this->assertSame(Images::NGINX_IMAGE, $parsed['services']['app']['image']);
        $this->assertSame(['8080:80'], $parsed['services']['app']['ports']);
        $this->assertSame(
            [
                './dist:/usr/share/nginx/html:ro',
                './' . NginxConfig::FILENAME . ':/etc/nginx/conf.d/default.conf:ro',
            ],
            $parsed['services']['app']['volumes']
        );
        $this->assertArrayNotHasKey('build', $parsed['services']['app']);
        $this->assertArrayNotHasKey('environment', $parsed['services']['app']);
    }

    public function test_framework_node_runtime_injects_port_and_url(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            [
                'runtime' => PlatformManifest::RUNTIME_NODE,
                'env' => ['NITRO_PRESET' => 'node-server'],
            ],
            3000,
            'https://app.example.test'
        ));

        $this->assertSame('3000', $parsed['services']['app']['environment']['PORT']);
        $this->assertSame('https://app.example.test', $parsed['services']['app']['environment']['APP_URL']);
        $this->assertSame('node-server', $parsed['services']['app']['environment']['NITRO_PRESET']);
        $this->assertSame(['3000:3000'], $parsed['services']['app']['ports']);
        $this->assertSame(['.env'], $parsed['services']['app']['env_file']);
    }

    public function test_nitro_standalone_bind_mounts_output_without_build(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            [
                'strategy' => Strategies::TANSTACK,
                'runtime' => PlatformManifest::RUNTIME_NODE,
                'output_directory' => '.output',
                'env' => ['NITRO_PRESET' => 'node-server'],
            ],
            3000,
            'https://site.example.test'
        ));

        $this->assertSame(Images::NODE_IMAGE, $parsed['services']['app']['image']);
        $this->assertSame('node ' . StandaloneNodeServe::FILENAME, $parsed['services']['app']['command']);
        $this->assertSame(
            [
                './' . StandaloneNodeServe::FILENAME . ':/app/' . StandaloneNodeServe::FILENAME . ':ro',
                './.output:/app/.output:ro',
                './dist:/app/dist:ro',
                './node_modules:/app/node_modules:ro',
            ],
            $parsed['services']['app']['volumes']
        );
        $this->assertSame('/app', $parsed['services']['app']['working_dir']);
        $this->assertSame('node-server', $parsed['services']['app']['environment']['NITRO_PRESET']);
        $this->assertSame('https://site.example.test', $parsed['services']['app']['environment']['APP_URL']);
        $this->assertArrayNotHasKey('build', $parsed['services']['app']);
    }

    public function test_standalone_bun_lockfile_runs_under_bun(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            [
                'strategy' => Strategies::TANSTACK,
                'runtime' => PlatformManifest::RUNTIME_NODE,
                'image' => HostNodeBuild::BUN_IMAGE,
            ],
            3000
        ));

        $this->assertSame(HostNodeBuild::BUN_IMAGE, $parsed['services']['app']['image']);
        $this->assertSame('bun ' . StandaloneNodeServe::FILENAME, $parsed['services']['app']['command']);
        $this->assertContains('./node_modules:/app/node_modules:ro', $parsed['services']['app']['volumes']);
    }

    public function test_framework_compose_attaches_sidecars_and_depends_on(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            [
                'runtime' => 'php',
                'env' => ['DB_HOST' => 'mysql'],
                'sidecars' => [
                    'mysql' => ['image' => 'mysql:8.4'],
                    'redis' => ['image' => 'redis:alpine'],
                ],
                'depends_on' => ['mysql', 'redis'],
                'volumes' => ['sail-mysql' => null],
            ],
            8000
        ));

        $this->assertSame('mysql:8.4', $parsed['services']['mysql']['image']);
        $this->assertSame('redis:alpine', $parsed['services']['redis']['image']);
        $this->assertSame(
            ['mysql' => ['condition' => 'service_started'], 'redis' => ['condition' => 'service_started']],
            $parsed['services']['app']['depends_on']
        );
        $this->assertSame('mysql', $parsed['services']['app']['environment']['DB_HOST']);
        $this->assertArrayHasKey('sail-mysql', $parsed['volumes']);
    }

    /**
     * A sidecar with a healthcheck is waited on until it passes: Servas runs
     * `artisan migrate` on boot and lost the race to its MariaDB (engine#187).
     */
    public function test_the_app_waits_for_a_sidecar_that_has_a_healthcheck(): void
    {
        $parsed = Yaml::parse(DeployCompose::framework(
            [
                'runtime' => 'php',
                'sidecars' => [
                    'db' => ['image' => 'mariadb:11', 'healthcheck' => ['test' => ['CMD-SHELL', 'mariadb-admin ping']]],
                    'redis' => ['image' => 'redis:7'],
                    'cache' => ['image' => 'memcached', 'healthcheck' => ['disable' => true]],
                ],
                'depends_on' => ['db', 'redis', 'cache'],
            ],
            8000
        ));

        $this->assertSame(
            [
                'db' => ['condition' => 'service_healthy'],
                'redis' => ['condition' => 'service_started'],
                'cache' => ['condition' => 'service_started'],
            ],
            $parsed['services']['app']['depends_on']
        );
    }

    public function test_railpack_compose_runs_image_without_bind_mount(): void
    {
        $parsed = Yaml::parse(DeployCompose::railpack('panelalpha-alice-app:latest', 3000));

        $this->assertSame('panelalpha-alice-app:latest', $parsed['services']['app']['image']);
        $this->assertSame(['3000:3000'], $parsed['services']['app']['ports']);
        $this->assertArrayNotHasKey('build', $parsed['services']['app']);
        $this->assertArrayNotHasKey('volumes', $parsed['services']['app']);
        $this->assertSame('railpack', $parsed['services']['app']['labels']['panelalpha.generated']);
    }

    public function test_skip_build_only_for_image_only_strategies(): void
    {
        $this->assertTrue(DeployCompose::skipBuild(Strategies::STATIC));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::FALLBACK));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::RAILPACK));
        $this->assertFalse(DeployCompose::skipBuild(Strategies::DOCKERFILE));
        $this->assertFalse(DeployCompose::skipBuild(Strategies::VITE));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::VITE, PlatformManifest::RUNTIME_NGINX));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::TANSTACK, PlatformManifest::RUNTIME_NODE));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::NUXT, PlatformManifest::RUNTIME_NODE));
        // Mounted project, stock image: nothing to build.
        $this->assertTrue(DeployCompose::skipBuild(Strategies::NEXTJS, PlatformManifest::RUNTIME_NODE));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::NESTJS, PlatformManifest::RUNTIME_NODE));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::EXPRESS, PlatformManifest::RUNTIME_NODE));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::DJANGO, PlatformManifest::RUNTIME_COMMAND));
        $this->assertTrue(DeployCompose::skipBuild(Strategies::GO, PlatformManifest::RUNTIME_COMMAND));
        // Ruby has its own strategy class and still builds an image.
        $this->assertFalse(DeployCompose::skipBuild(Strategies::RAILS, PlatformManifest::RUNTIME_COMMAND));
        $this->assertFalse(DeployCompose::skipBuild(Strategies::PHP));
        $this->assertFalse(DeployCompose::skipBuild(null));
    }

    public function test_skip_reclaim_for_static_output_not_php_or_ssr(): void
    {
        $this->assertTrue(DeployCompose::skipReclaimBeforeBuild(Strategies::STATIC));
        $this->assertTrue(DeployCompose::skipReclaimBeforeBuild(Strategies::VITE, PlatformManifest::RUNTIME_NGINX));
        $this->assertTrue(DeployCompose::skipReclaimBeforeBuild(Strategies::TANSTACK, PlatformManifest::RUNTIME_NODE));
        $this->assertTrue(DeployCompose::skipReclaimBeforeBuild(Strategies::NEXTJS, PlatformManifest::RUNTIME_NODE));
        $this->assertFalse(DeployCompose::skipReclaimBeforeBuild(Strategies::RAILS, PlatformManifest::RUNTIME_COMMAND));
        $this->assertFalse(DeployCompose::skipReclaimBeforeBuild(Strategies::PHP));
    }

    public function test_image_refs_cover_sidecars_but_not_locally_built_services(): void
    {
        $compose = <<<YAML
        services:
          app:
            build:
              context: .
            image: project-app
          db:
            image: mysql:8.4
          cache:
            image: redis
          search:
            image: \${SEARCH_IMAGE}
          pinned:
            image: nginx@sha256:abc
        YAML;

        $this->assertSame(['mysql:8.4', 'redis:latest'], DeployCompose::imageRefs($compose));
    }

    public function test_image_refs_are_capped_and_deduplicated(): void
    {
        $services = [];
        foreach (range(1, 9) as $i) {
            $services["s{$i}"] = ['image' => "redis:{$i}"];
        }
        $services['dupe'] = ['image' => 'redis:1'];

        $refs = DeployCompose::imageRefs(Yaml::dump(['services' => $services]), 4);

        $this->assertCount(4, $refs);
        $this->assertSame($refs, array_unique($refs));
    }

    public function test_image_refs_tolerate_files_that_are_not_compose(): void
    {
        $this->assertSame([], DeployCompose::imageRefs('this: [is: not: valid'));
        $this->assertSame([], DeployCompose::imageRefs('services: null'));
    }
}
