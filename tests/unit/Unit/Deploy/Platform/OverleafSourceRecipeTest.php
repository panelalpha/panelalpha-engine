<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\OverleafSourceRecipeFixtures;

/**
 * Overleaf pins mongo:8.0, which refuses to start on Linux 6.19+ (SERVER-121912),
 * and Overleaf 6.x refuses MongoDB below 8.0, so the recipe runs mongo 8.2.
 */
class OverleafSourceRecipeTest extends TestCase
{
    use OverleafSourceRecipeFixtures;

    public function test_the_recipe_replaces_the_compose_with_mongo_8_2_as_a_replica_set(): void
    {
        $dir = SourceRecipes::directoryFor(self::URL);
        $this->assertNotNull($dir);
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $dir, true);
        $this->assertNotNull($config);
        $compose = Yaml::parse((string) $config->replacingCompose());

        $mongo = $compose['services']['mongo'];
        $this->assertStringStartsWith('mongo:8.2.12@sha256:', $mongo['image']);
        $this->assertSame(['--replSet', 'overleaf'], array_slice($mongo['command'], 0, 2));
        $this->assertStringStartsWith('./bin/shared/mongodb-init-replica-set.js:', $mongo['volumes'][1]);
        $this->assertStringStartsWith('sharelatex/sharelatex:6.3.0@sha256:', $compose['services']['sharelatex']['image']);
        $this->assertSame('${PA_PUBLIC_URL}', $compose['services']['sharelatex']['environment']['OVERLEAF_SITE_URL']);
    }
}
