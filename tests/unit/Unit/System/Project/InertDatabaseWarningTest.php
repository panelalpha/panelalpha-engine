<?php

namespace Tests\Unit\System\Project;

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
}
