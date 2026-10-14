<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Tiki: index.php and dozens of PHP entry points at the root, composer.json.dist
 * instead of composer.json, and a Vite build that writes assets into the PHP
 * tree. The Vite platform claimed it and failed on a missing dist/index.html.
 */
class VitePhpFrontControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-vite-php-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/package.json', json_encode([
            'scripts' => ['build' => 'vite --config=src/js/vite.config.mjs build'],
            'devDependencies' => ['vite' => '^6.0.0'],
        ]));
        file_put_contents($this->dir . '/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_a_root_php_front_controller_is_not_a_vite_spa(): void
    {
        file_put_contents($this->dir . '/index.php', "<?php\nrequire 'tiki-setup.php';\n");
        file_put_contents($this->dir . '/tiki-setup.php', "<?php\n");
        file_put_contents($this->dir . '/composer.json.dist', '{}');

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertNotSame('vite', $decision['strategy']);
        $this->assertSame('php', $decision['strategy']);
    }

    public function test_a_vite_spa_is_still_vite(): void
    {
        file_put_contents($this->dir . '/index.html', '<!doctype html><div id="app"></div>');

        $this->assertSame('vite', DetectProjectStrategy::detect($this->dir)['strategy']);
    }
}
