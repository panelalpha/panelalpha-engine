<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class DindAccountCleanupTest extends TestCase
{
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
}
