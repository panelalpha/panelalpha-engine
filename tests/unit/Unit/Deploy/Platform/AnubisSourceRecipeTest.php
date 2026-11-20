<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AnubisSourceRecipeFixtures;

/**
 * Anubis's challenge assets come from a Makefile the Go strategy cannot run,
 * so the recipe runs the published image in front of a placeholder site.
 */
class AnubisSourceRecipeTest extends TestCase
{
    use AnubisSourceRecipeFixtures;

    public function test_a_fresh_deploy_protects_the_placeholder_until_target_is_set(): void
    {
        $dir = SourceRecipes::directoryFor(self::URL);
        $this->assertNotNull($dir);
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $dir, true);
        $this->assertNotNull($config);
        $compose = Yaml::parse((string) $config->replacingCompose());

        $anubis = $compose['services']['anubis'];
        $this->assertStringStartsWith('ghcr.io/techarohq/anubis:v1.27.0@sha256:', $anubis['image']);
        $this->assertSame('${TARGET:-http://backend:80}', $anubis['environment']['TARGET']);
        $this->assertSame('${TARGET_HOST:-}', $anubis['environment']['TARGET_HOST']);
        $this->assertSame(['8923:8923'], $anubis['ports']);
        $this->assertContains('panelalpha/anubis/index.html', array_column($config->files(), 'path'));
    }
}
