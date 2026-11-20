<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * A decision naming a platform only the project's own app config describes, when that description cannot be a manifest.
 */
class PlatformRegistryAppConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        SourceRecipes::flush();
        parent::tearDown();
    }

    public function test_an_app_config_whose_manifest_is_refused_names_no_platform(): void
    {
        $appConfig = AppConfig::fromYaml("id: acme-own\nlabel: Acme\nstrategy: static\ndetect:\n  file: index.html\n");

        $this->assertNull(PlatformRegistry::forDecisionOrAppConfig(['platform' => 'acme-own'], $appConfig));
    }

    public function test_an_app_config_describing_another_platform_is_not_the_decisions(): void
    {
        $appConfig = AppConfig::fromYaml("id: acme-own\nlabel: Acme\nstrategy: static\n");

        $this->assertNull(PlatformRegistry::forDecisionOrAppConfig(['platform' => 'acme-other'], $appConfig));
        $this->assertSame('acme-own', PlatformRegistry::forDecisionOrAppConfig(['platform' => 'acme-own'], $appConfig)?->id);
    }
}
