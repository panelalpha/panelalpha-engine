<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformMatcher;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * The `composer:` detect rule: a package required or required for development matches, a project without composer.json never does.
 */
class PlatformMatcherComposerRuleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-matcher-composer-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function requiresPackage(string $package): bool
    {
        return (new PlatformMatcher(ProbeRegistry::all()))->matches(['composer' => $package], ProjectContext::at($this->dir));
    }

    public function test_a_project_without_composer_json_never_matches(): void
    {
        $this->assertFalse($this->requiresPackage('laravel/framework'));
    }

    public function test_a_required_or_dev_required_package_matches(): void
    {
        file_put_contents($this->dir.'/composer.json', '{"require":{"laravel/framework":"^11"},"require-dev":{"phpunit/phpunit":"^11"}}');

        $this->assertTrue($this->requiresPackage('laravel/framework'));
        $this->assertTrue($this->requiresPackage('phpunit/phpunit'));
        $this->assertFalse($this->requiresPackage('symfony/symfony'));
    }
}
