<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\ComposerMetadataOnlyProbe;

/**
 * SVG-edit ships a composer.json only so Packagist lists it; the project is a
 * Vite SPA and the php platform deployed it to a 403.
 */
class ComposerMetadataOnlyProbeTest extends ProbeTestCase
{
    private const SVGEDIT = ['name' => 'svg-edit/svgedit', 'type' => 'library', 'require' => []];

    private function probe(): ComposerMetadataOnlyProbe
    {
        return new ComposerMetadataOnlyProbe();
    }

    public function test_a_library_with_no_packages_and_no_php_is_metadata(): void
    {
        $this->writeJson('composer.json', self::SVGEDIT);
        // Deeper than the PHP walk goes, like svgedit's archived server extension.
        $this->write('archive/untested/ext-server/savefile.php', '<?php');

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    public function test_php_in_the_project_keeps_it_php(): void
    {
        $this->writeJson('composer.json', self::SVGEDIT);
        $this->write('src/index.php', '<?php');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /** drupal/recommended-project has no PHP until Composer scaffolds it. */
    public function test_a_required_package_keeps_it_php(): void
    {
        $this->writeJson('composer.json', ['type' => 'library', 'require' => ['php' => '>=8.1', 'drupal/core' => '^11']]);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_project_type_or_no_type_keeps_it_php(): void
    {
        $this->writeJson('composer.json', ['name' => 'app/app', 'type' => 'project']);
        $this->assertFalse($this->probe()->evaluate($this->context()));

        $this->writeJson('composer.json', ['name' => 'app/app']);
        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_the_vite_spa_is_detected_as_vite_not_php(): void
    {
        $this->writeJson('composer.json', self::SVGEDIT);
        $this->writeJson('package.json', [
            'name' => 'svgedit',
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^7.0.0'],
        ]);
        $this->write('index.html', '<!doctype html><title>SVG-edit</title>');

        $this->assertSame('vite', DetectProjectStrategy::detect($this->dir)['strategy']);
    }
}
