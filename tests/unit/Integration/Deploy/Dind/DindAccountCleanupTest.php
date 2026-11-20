<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Dind\AccountImage;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use App\Lib\Deploy\ProjectCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class DindAccountCleanupTest extends TestCase
{
    public function test_host_cleanup_removes_the_node_modules_volume_through_the_broker(): void
    {
        [$store] = DindAccountCleanup::hostCleanupArgvs('acme-shop', '/home/acme-shop');

        $this->assertTrue(LocalRoot::isRequest($store));
        $this->assertSame('--host', $store[1]);
        $this->assertSame('host.removeAccountDockerStore', $store[2]);
        $this->assertSame(
            [HostNodeBuild::nodeModulesVolumeName('acme-shop'), '0'],
            array_slice($store, 4)
        );
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
        [, $files] = DindAccountCleanup::hostCleanupArgvs('acme-shop', '/home/acme-shop');

        $this->assertSame([ProjectCache::dirFor('acme-shop')], array_slice($files, 4, 1));
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
        [, $files] = DindAccountCleanup::hostCleanupArgvs('acme-shop', '/home/acme-shop');

        foreach (ProjectCache::legacyDirsFor('acme-shop') as $legacy) {
            $this->assertContains($legacy, $files);
        }
    }

    public function test_host_cleanup_never_reaches_another_account_s_composer_cache(): void
    {
        [, $files] = DindAccountCleanup::hostCleanupArgvs('acme', '/home/acme');

        $this->assertNotContains(PhpHostBuild::cacheDirFor('acme-shop'), $files);
        // The shared parent as an argument of its own would wipe every
        // account's cache, not this one's.
        $this->assertNotContains(ProjectCache::ROOT, $files);
    }

    public function test_host_cleanup_removes_sidecar_images_but_not_prewarm(): void
    {
        [$store] = DindAccountCleanup::hostCleanupArgvs(
            'shopware',
            '/home/shopware',
            ['opensearchproject/opensearch:2', 'mysql:8.4']
        );

        $this->assertSame(['2', 'opensearchproject/opensearch:2', 'mysql:8.4'], array_slice($store, 5));
        $this->assertNotContains('nginx:alpine', $store);
        $this->assertNotContains('panelalpha/php', $store);
    }

    public function test_host_cleanup_rejects_unsafe_paths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DindAccountCleanup::hostCleanupArgvs('alice', '/tmp/alice');
    }

    public function test_sidecar_removal_keeps_prewarm_engine_and_in_use_images(): void
    {
        $account = [
            'opensearchproject/opensearch:2',
            'mysql:8.4',
            Images::NGINX_IMAGE,
            'node:20-bookworm-slim',
            AccountImage::REF,
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
        [$store] = DindAccountCleanup::hostCleanupArgvs(
            'acme',
            '/home/acme',
            ['mysql:8.4', 'evil; rm -rf /', '']
        );

        $this->assertSame(['1', 'mysql:8.4'], array_slice($store, 5));
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
        $request = DindAccountCleanup::otherAccountComposeImagesArgv('/home/', 'shopware');
        $argv = LocalRoot::plain($request);

        $this->assertTrue(LocalRoot::isRequest($request));
        $this->assertSame(['sh', '-c'], array_slice($argv, 0, 2));
        $script = $argv[2];
        $this->assertStringContainsString("/*/project/docker-compose.yml", $script);
        $this->assertStringContainsString("/*/project/compose.yaml", $script);
        $this->assertStringContainsString('"$1/$2"/*) continue', $script);
        $this->assertSame(['sh', '/home', 'shopware'], array_slice($argv, 3));
    }

    /**
     * ADR-0001: a recipe deploy's images live only under the engine's
     * reserved run-file names now, never under a name the client would
     * recognise — missing them here would mean another account's images
     * get pruned as soon as it redeploys onto the new layout.
     */
    public function test_sibling_compose_scan_also_covers_the_reserved_run_file_names(): void
    {
        $argv = LocalRoot::plain(DindAccountCleanup::otherAccountComposeImagesArgv('/home/', 'shopware'));
        $script = $argv[2];

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
