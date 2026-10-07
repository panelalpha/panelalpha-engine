<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind\Strategy\ManifestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A `database:` no writer provisions must say so in the deploy
 * log. The dockerfile and generated strategies honour it now, so the warning
 * is left only where the key is still inert.
 */
class InertDatabaseWarningTest extends TestCase
{
    public function test_a_strategy_that_does_not_provision_is_warned_about(): void
    {
        $warning = ManifestDatabase::inertWarning(['database' => 'mysql', 'strategy' => Strategies::COMPOSE]);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('database: mysql', $warning);
        $this->assertStringContainsString('strategy compose', $warning);
    }

    public function test_php_dockerfile_or_no_database_says_nothing(): void
    {
        $this->assertNull(ManifestDatabase::inertWarning(['database' => 'mysql', 'strategy' => Strategies::PHP]));
        $this->assertNull(ManifestDatabase::inertWarning(['database' => 'mysql', 'strategy' => Strategies::DOCKERFILE]));
        $this->assertNull(ManifestDatabase::inertWarning(['database' => null, 'strategy' => Strategies::COMPOSE]));
        $this->assertNull(ManifestDatabase::inertWarning(['strategy' => Strategies::COMPOSE]));
    }

    /** The shipped case: Kimai is dockerfile + `database: mysql`, now provisioned, so no warning. */
    public function test_the_kimai_recipe_is_no_longer_warned_about(): void
    {
        $dir = sys_get_temp_dir() . '/pa-kimai-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/Dockerfile', "FROM php:8.3-apache-bookworm\n");
        SourceRecipes::flush();

        try {
            $decision = DetectProjectStrategy::detect($dir, 'https://github.com/kimai/kimai');
        } finally {
            @unlink($dir . '/Dockerfile');
            @rmdir($dir);
        }

        $this->assertSame('kimai', $decision['platform']);
        $this->assertSame('mysql', $decision['database']);
        $this->assertNull(ManifestDatabase::inertWarning($decision));
    }
}
