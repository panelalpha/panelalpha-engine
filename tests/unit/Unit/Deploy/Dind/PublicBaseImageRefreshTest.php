<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * `node:22-bookworm-slim` moves with every Node patch release. An account or a
 * host that already held it never pulled it again, so a redeploy after the
 * Node.js security release of 21.10.2026 still built on the vulnerable Node.
 */
class PublicBaseImageRefreshTest extends TestCase
{
    private const NODE = 'node:22-bookworm-slim';

    public function test_a_public_tag_follows_upstream(): void
    {
        $this->assertTrue(DindImageStore::followsUpstream(self::NODE));
        $this->assertTrue(DindImageStore::followsUpstream('nginx:alpine'));
    }

    public function test_ours_and_digest_pinned_images_do_not(): void
    {
        $this->assertFalse(DindImageStore::followsUpstream('panelalpha/php:8.3-apache-bookworm-pa20260924'));
        $this->assertFalse(DindImageStore::followsUpstream('node:22-bookworm-slim@sha256:' . str_repeat('a', 64)));
    }

    public function test_the_account_pulls_again_and_says_whether_it_changed(): void
    {
        $account = new EngineAccount('demo', '/home/demo', '33:33');
        $script = (new DindImageStore())->refreshCommand($account, self::NODE);
        // Through the account's own daemon, which mirrors Docker Hub via registry-proxy.
        $this->assertStringContainsString(LocalRoot::CLIENT.' account-docker.pull -- demo '.self::NODE, $script);
        $this->assertStringContainsString(LocalRoot::CLIENT.' account-docker.images -- demo '.self::NODE, $script);
        $this->assertStringNotContainsString(DindImageStore::CACHE_REGISTRY, $script);
        $this->assertStringContainsString('Updated base image', $script);
        $this->assertStringContainsString('is current', $script);
    }

    public function test_the_host_pulls_a_public_build_image_again_and_never_fails(): void
    {
        $argv = (new DindImageStore())->hostRefreshImageArgv(self::NODE);

        $this->assertSame([LocalRoot::CLIENT, '--host', 'host.refreshPublicImage', '--', self::NODE], $argv);
        $this->assertSame([], LocalRoot::plain($argv));
    }

    public function test_the_host_leaves_ours_alone(): void
    {
        $this->assertSame(['true'], (new DindImageStore())->hostRefreshImageArgv('panelalpha/php:8.3-apache-bookworm-pa20260924'));
    }
}
