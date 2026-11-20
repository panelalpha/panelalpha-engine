<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Runtime\Php\PhpBuild;
use App\System\Project\Dind\Strategy\PhpStrategy;
use PHPUnit\Framework\TestCase;

/** A Symfony app is one whose composer.json requires the framework at the root, Laravel or not. */
class PhpStrategySymfonyTest extends TestCase
{
    public function test_a_root_requirement_of_either_symfony_package_makes_it_symfony(): void
    {
        $this->assertTrue(PhpStrategy::isSymfony(new PhpBuild(composerJson: '{"require":{"symfony/framework-bundle":"^7.1"}}', artisan: false)));
        $this->assertTrue(PhpStrategy::isSymfony(new PhpBuild(composerJson: '{"require":{"symfony/symfony":"^5.4"}}', artisan: true)));
    }

    public function test_a_laravel_app_is_not_symfony_through_its_dependencies(): void
    {
        $installed = '{"packages":[{"name":"symfony/framework-bundle"}]}';

        $this->assertFalse(PhpStrategy::isSymfony(new PhpBuild(composerJson: '{"require":{"laravel/framework":"^11"}}', artisan: true), $installed));
    }
}
