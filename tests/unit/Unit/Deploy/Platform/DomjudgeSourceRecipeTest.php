<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\DomjudgeSourceRecipeFixtures;

/**
 * DOMjudge's own compose is its contributor stack; the recipe runs the
 * published domserver with MariaDB and no judgehost.
 */
class DomjudgeSourceRecipeTest extends TestCase
{
    use DomjudgeSourceRecipeFixtures;

    public function test_the_recipe_runs_the_published_domserver_on_mariadb_without_a_judgehost(): void
    {
        $dir = SourceRecipes::directoryFor(self::URL);
        $this->assertNotNull($dir);
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $dir, true);
        $this->assertNotNull($config);
        $compose = Yaml::parse((string) $config->replacingCompose());

        $this->assertSame(['domserver', 'mariadb', 'ready'], array_keys($compose['services']));
        $domserver = $compose['services']['domserver'];
        $this->assertStringStartsWith('domjudge/domserver:9.0.0@sha256:', $domserver['image']);
        $this->assertSame('mariadb', $domserver['environment']['MYSQL_HOST']);
        $this->assertArrayNotHasKey('privileged', $domserver);
        $this->assertStringStartsWith('mariadb:11.4@sha256:', $compose['services']['mariadb']['image']);
        $this->assertContains('--max-connections=1000', $compose['services']['mariadb']['command']);
        $this->assertSame('db:/var/lib/mysql', $compose['services']['mariadb']['volumes'][0]);
    }
}
