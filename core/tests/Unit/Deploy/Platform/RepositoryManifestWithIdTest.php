<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageResolver;
use PHPUnit\Framework\TestCase;

/**
 * A repository's own .panelalpha/panelalpha.yaml with an `id:` is chosen by
 * detection under that id, which no registry knows; the entrypoint writer
 * found nothing and none of its install, upgrade or start commands ran.
 */
class RepositoryManifestWithIdTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-own-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.panelalpha', 0o777, true);
        file_put_contents($this->dir . '/composer.json', '{"name":"acme/app","require":{"php":"^8.3"}}');
        file_put_contents($this->dir . '/index.php', '<?php echo 1;');
        file_put_contents($this->dir . '/.panelalpha/panelalpha.yaml', <<<'YAML'
id: acme-app
extends: php
commands:
  - id: boot-db
    stage: upgrade
    run: 'php bin/boot-db.php'
YAML);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_the_manifest_detection_chose_resolves_for_the_entrypoint(): void
    {
        $decision = DetectProjectStrategy::detect($this->dir);
        $appConfig = AppConfig::load(new LocalAppConfigSource(), $this->dir);

        $this->assertSame('acme-app', $decision['platform']);
        $this->assertNull(PlatformRegistry::forDecision($decision), 'the id is registered nowhere');

        $manifest = PlatformRegistry::forDecisionOrAppConfig($decision, $appConfig);

        $this->assertNotNull($manifest);
        $this->assertSame('acme-app', $manifest->id);
        $this->assertNotNull($manifest->serveCommand(), 'inherited from php');
        // What the entrypoint renders for the stage: the manifest's commands plus the app config's.
        $this->assertContains('boot-db', array_map(
            static fn ($command) => $command->id,
            StageResolver::commandsFor(PlatformStage::UPGRADE, $manifest, $appConfig)
        ));
    }

    public function test_a_shipped_platform_still_comes_from_the_registry(): void
    {
        $manifest = PlatformRegistry::forDecisionOrAppConfig(['platform' => 'php'], null);

        $this->assertSame(PlatformRegistry::find('php'), $manifest);
        $this->assertNull(PlatformRegistry::forDecisionOrAppConfig(['platform' => 'nowhere'], null));
    }

    /** Another app config's manifest is not a stand-in for the platform the decision names. */
    public function test_an_app_config_with_a_different_id_is_not_used(): void
    {
        $appConfig = AppConfig::load(new LocalAppConfigSource(), $this->dir);

        $this->assertNull(PlatformRegistry::forDecisionOrAppConfig(['platform' => 'other-app'], $appConfig));
    }
}
