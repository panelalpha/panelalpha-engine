<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\ProjectCache;
use PHPUnit\Framework\TestCase;

class DindAccountCleanupTest extends TestCase
{
    public function test_host_cleanup_removes_node_modules_volume_and_inner_data_root(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv('acme-shop', '/home/acme-shop');

        $this->assertSame(
            ['sudo', 'nsenter', '--target', '1', '--all', 'sh', '-c'],
            array_slice($argv, 0, 7)
        );
        $script = $argv[array_key_last($argv)];
        $this->assertStringContainsString(
            'docker volume rm -f ' . escapeshellarg(HostNodeBuild::nodeModulesVolumeName('acme-shop')),
            $script
        );
        $this->assertStringContainsString(escapeshellarg('/home/acme-shop/docker'), $script);
        $this->assertStringContainsString(escapeshellarg(HostNodeBuild::cacheDirFor('acme-shop')), $script);
        $this->assertStringNotContainsString('docker rmi', $script);
    }

    /**
     * The layout used to be cache-first, so this script had to name each cache
     * and only ever named the JS one -- every deleted PHP account leaked ~19MB
     * of Composer cache. Project-first means removing one directory covers
     * every cache, including ones added after this test was written, so that
     * is what is asserted rather than a list that would drift the same way.
     */
    public function test_host_cleanup_removes_every_cache_by_removing_the_project_directory(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv('acme-shop', '/home/acme-shop');
        $script = $argv[array_key_last($argv)];

        $this->assertStringContainsString(escapeshellarg(ProjectCache::dirFor('acme-shop')), $script);
        foreach (ProjectCache::NAMES as $name) {
            $this->assertStringStartsWith(
                ProjectCache::dirFor('acme-shop') . '/',
                ProjectCache::subdirFor('acme-shop', $name)
            );
        }
        $this->assertStringStartsWith(
            ProjectCache::dirFor('acme-shop') . '/',
            PhpHostBuild::cacheDirFor('acme-shop')
        );
    }

    /**
     * A host upgrading mid-life may still be carrying the old locations for an
     * account that never redeployed onto the new layout.
     */
    public function test_host_cleanup_still_removes_the_pre_project_scoped_directories(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv('acme-shop', '/home/acme-shop');
        $script = $argv[array_key_last($argv)];

        foreach (ProjectCache::legacyDirsFor('acme-shop') as $legacy) {
            $this->assertStringContainsString(escapeshellarg($legacy), $script);
        }
    }

    public function test_host_cleanup_never_reaches_another_account_s_composer_cache(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv('acme', '/home/acme');
        $script = $argv[array_key_last($argv)];

        $this->assertStringNotContainsString(PhpHostBuild::cacheDirFor('acme-shop'), $script);
        // The shared parent as an argument of its own would wipe every
        // account's cache, not this one's.
        $this->assertStringNotContainsString("'" . ProjectCache::ROOT . "'", $script);
    }

    public function test_host_cleanup_removes_sidecar_images_but_not_prewarm(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv(
            'shopware',
            '/home/shopware',
            ['opensearchproject/opensearch:2', 'mysql:8.4']
        );
        $script = $argv[array_key_last($argv)];

        $this->assertStringContainsString('docker rmi ', $script);
        $this->assertStringContainsString(escapeshellarg('opensearchproject/opensearch:2'), $script);
        $this->assertStringContainsString(escapeshellarg('mysql:8.4'), $script);
        $this->assertStringNotContainsString('nginx:alpine', $script);
        $this->assertStringNotContainsString('panelalpha/php', $script);
    }

    /**
     * -f would untag an image a stopped host container is waiting on, and the
     * daemon's own refusal is a better guard than anything we can enumerate.
     */
    public function test_host_cleanup_never_forces_image_removal(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv('shopware', '/home/shopware', ['mysql:8.4']);

        $this->assertStringNotContainsString('docker rmi -f', $argv[array_key_last($argv)]);
    }

    public function test_host_cleanup_rejects_unsafe_paths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DindAccountCleanup::hostCleanupArgv('alice', '/tmp/alice');
    }

    public function test_sidecar_removal_keeps_prewarm_engine_and_in_use_images(): void
    {
        $account = [
            'opensearchproject/opensearch:2',
            'mysql:8.4',
            Images::NGINX_IMAGE,
            'node:20-bookworm-slim',
            'ghcr.io/panelalpha/engine-user-dind:20260907',
            'panelalpha/php:8.4-apache-bookworm-pab1ff14ca',
            'postgres:16',
        ];
        $inUse = ['ghcr.io/panelalpha/engine-core:20260603'];

        $remove = DindAccountCleanup::hostSidecarRefsToRemove($account, $inUse);

        $this->assertSame(
            ['opensearchproject/opensearch:2', 'mysql:8.4', 'postgres:16'],
            $remove
        );
    }

    public function test_protects_prewarm_catalog_and_panelalpha_images(): void
    {
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage(Images::NGINX_IMAGE));
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage(Images::NODE_IMAGE));
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage('oven/bun:1'));
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage('ghcr.io/panelalpha/engine-lighthouse:20260526'));
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage('panelalpha/php:8.3-apache-bookworm-paoldfinger'));
        $this->assertFalse(DindAccountCleanup::isProtectedHostImage('opensearchproject/opensearch:2'));
        $this->assertFalse(DindAccountCleanup::isProtectedHostImage('ghcr.io/shopware/docker-dev:php8.4-node24-caddy'));
        $this->assertFalse(DindAccountCleanup::isProtectedHostImage('typesense/typesense:27.1'));
    }

    public function test_skips_unsafe_sidecar_refs_in_argv(): void
    {
        $argv = DindAccountCleanup::hostCleanupArgv(
            'acme',
            '/home/acme',
            ['mysql:8.4', 'evil; rm -rf /', '']
        );
        $script = $argv[array_key_last($argv)];

        $this->assertStringContainsString(escapeshellarg('mysql:8.4'), $script);
        $this->assertStringNotContainsString('rm -rf /', $script);
    }

    public function test_sidecar_removal_keeps_images_another_account_declares(): void
    {
        $remove = DindAccountCleanup::hostSidecarRefsToRemove(
            ['opensearchproject/opensearch:2', 'mysql:8.4'],
            ['mysql:8.4']
        );

        $this->assertSame(['opensearchproject/opensearch:2'], $remove);
    }

    public function test_sibling_compose_scan_skips_the_account_being_deleted(): void
    {
        $argv = DindAccountCleanup::otherAccountComposeImagesArgv('/home/', 'shopware');

        $this->assertSame(['sudo', 'sh', '-c'], array_slice($argv, 0, 3));
        $script = $argv[3];
        $this->assertStringContainsString("/*/project/docker-compose.yml", $script);
        $this->assertStringContainsString("/*/project/compose.yaml", $script);
        $this->assertStringContainsString(escapeshellarg('/home/shopware') . '/*) continue', $script);
    }

    /**
     * ADR-0001: a recipe deploy's images live only under the engine's
     * reserved run-file names now, never under a name the client would
     * recognise — missing them here would mean another account's images
     * get pruned as soon as it redeploys onto the new layout.
     */
    public function test_sibling_compose_scan_also_covers_the_reserved_run_file_names(): void
    {
        $argv = DindAccountCleanup::otherAccountComposeImagesArgv('/home/', 'shopware');
        $script = $argv[3];

        $this->assertStringContainsString('/*/project/' . EngineArtifacts::RUN_COMPOSE, $script);
        $this->assertStringContainsString('/*/project/' . EngineArtifacts::RUN_COMPOSE_OVERRIDE, $script);
        $this->assertStringContainsString('/*/project/' . EngineArtifacts::APP_CONFIG_COMPOSE, $script);
    }

    public function test_sibling_compose_scan_rejects_unsafe_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DindAccountCleanup::otherAccountComposeImagesArgv('/home', 'alice; rm -rf /');
    }

    public function test_parses_image_lines_and_drops_unresolvable_refs(): void
    {
        $output = <<<TXT
    image: mysql:8.4
    image: "redis:7"
      image: 'opensearchproject/opensearch:2'
    image: \${APP_IMAGE}
    image: node
    image: mysql:8.4
TXT;

        $this->assertSame(
            ['mysql:8.4', 'redis:7', 'opensearchproject/opensearch:2', 'node:latest'],
            DindAccountCleanup::parseComposeImageLines($output)
        );
    }

    /**
     * `docker images` and `docker ps --format {{.Image}}` both answer one ref
     * per line, and both callers used to keep their own copy of this loop —
     * which is how the two drifted, one normalising and the other not.
     */
    public function test_parses_one_ref_per_line_and_normalises_each(): void
    {
        $refs = DindAccountCleanup::parseImageRefLines(
            "postgres:16\ndocker.io/library/redis:7\n\n  mysql:8.4  \n"
        );

        $this->assertSame(['postgres:16', 'docker.io/library/redis:7', 'mysql:8.4'], $refs);
    }

    /**
     * An untagged name is the same image the host stored as `:latest`, and
     * asking docker to remove the bare name would miss it.
     */
    public function test_an_untagged_name_is_reported_as_latest(): void
    {
        $this->assertSame(['nginx:latest'], DindAccountCleanup::parseImageRefLines("nginx\n"));
    }

    /**
     * A compose file that interpolated a variable leaves a ref naming no real
     * image. Passing it to `docker rmi` is at best a no-op, so it is dropped.
     */
    public function test_an_unresolved_interpolation_is_not_a_ref(): void
    {
        $this->assertSame([], DindAccountCleanup::parseImageRefLines("app:\${TAG}\n"));
    }

    public function test_the_same_image_listed_twice_is_reported_once(): void
    {
        $refs = DindAccountCleanup::parseImageRefLines("postgres:16\npostgres:16\n");

        $this->assertSame(['postgres:16'], $refs);
    }

    public function test_empty_output_is_no_refs(): void
    {
        $this->assertSame([], DindAccountCleanup::parseImageRefLines(''));
        $this->assertSame([], DindAccountCleanup::parseImageRefLines("\n  \n"));
    }

    public function test_it_reads_windows_line_endings_too(): void
    {
        $refs = DindAccountCleanup::parseImageRefLines("postgres:16\r\nmysql:8.4\r\n");

        $this->assertSame(['postgres:16', 'mysql:8.4'], $refs);
    }
}
