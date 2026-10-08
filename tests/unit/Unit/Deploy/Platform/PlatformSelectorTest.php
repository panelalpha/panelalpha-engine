<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformSelector;
use Tests\TestCase;

/**
 * Which of the shipped manifests claims a project.
 *
 * This runs against the real shipped catalogue rather than
 * fixtures, because the thing worth testing is the priority order those files
 * declare between them: a Laravel repo is a PHP repo and a Next.js repo is a
 * Node repo, and the more specific manifest has to win or every framework
 * deploys as its generic base.
 */
class PlatformSelectorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-select-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function writeJson(string $relative, array $json): void
    {
        $this->write($relative, (string) json_encode($json));
    }

    private function selected(): ?string
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }
        $result = PlatformSelector::detect($this->dir, $files);

        return $result === null ? null : $result['manifest']->id;
    }

    public function test_a_project_nothing_recognises_is_claimed_by_nothing(): void
    {
        $this->write('README.md', '# just a readme');

        $this->assertNull($this->selected());
    }

    public function test_a_compose_file_outranks_everything_else(): void
    {
        // The project told us how it runs. Nothing the engine infers beats
        // that, so this manifest sits at the top of the order.
        $this->write('docker-compose.yml', "services:\n  app:\n    image: acme/app\n    ports: [\"8080:8080\"]\n");
        $this->writeJson('package.json', ['dependencies' => ['next' => '^14.0.0']]);
        $this->write('composer.json', '{"require": {"laravel/framework": "^11.0"}}');

        $this->assertSame('compose', $this->selected());
    }

    public function test_a_dockerfile_outranks_an_inferred_platform(): void
    {
        $this->write('Dockerfile', "FROM node:20\nCMD [\"node\", \"server.js\"]\n");
        $this->writeJson('package.json', ['dependencies' => ['express' => '^4.18.0']]);

        $this->assertSame('dockerfile', $this->selected());
    }

    public function test_laravel_beats_plain_php(): void
    {
        // Both match on composer.json. The framework manifest knows about
        // artisan, migrations and the public/ document root; the generic one
        // would serve the repository root.
        $this->writeJson('composer.json', ['require' => ['laravel/framework' => '^11.0']]);
        $this->write('artisan', '#!/usr/bin/env php');

        $this->assertSame('laravel', $this->selected());
    }

    public function test_a_php_project_without_a_framework_falls_to_the_generic_manifest(): void
    {
        $this->writeJson('composer.json', ['require' => ['guzzlehttp/guzzle' => '^7.8']]);
        $this->write('index.php', '<?php echo "hello";');

        $this->assertSame('php', $this->selected());
    }

    public function test_a_directory_of_html_is_a_static_site(): void
    {
        // And specifically not Railpack: nothing here needs building.
        $this->write('index.html', '<h1>hello</h1>');
        $this->write('style.css', 'body{}');

        $this->assertSame('static', $this->selected());
    }

    public function test_a_selection_carries_the_decision_the_manifest_describes(): void
    {
        $this->write('index.html', '<h1>hello</h1>');
        $result = PlatformSelector::detect($this->dir, ['index.html' => true]);

        $this->assertSame('static', $result['manifest']->id);
        $this->assertSame('index.html', $result['decision']['static_index']);
    }

    public function test_a_probe_contributes_to_the_decision_it_settled(): void
    {
        // The Dockerfile manifest cannot state the path or the port; the
        // probe that matched it supplies both.
        $this->write('Dockerfile', "FROM node:20\nEXPOSE 4000\n");
        $result = PlatformSelector::detect($this->dir, ['dockerfile' => true]);

        $this->assertSame('Dockerfile', $result['decision']['dockerfile']);
        $this->assertSame(4000, $result['decision']['port_hint']);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public function test_wordpress_beats_plain_php(): void
    {
        $this->write('wp-settings.php', '<?php // boot');
        $this->write('wp-login.php', '<?php // login');
        $this->write('wp-includes/version.php', '<?php $wp_version = "6.7";');
        $this->write('wp-admin/index.php', '<?php // dashboard');

        $this->assertSame('wordpress', $this->selected());
    }

    /**
     * The develop checkout ships a docker-compose.yml, and compose outranks
     * every inferred platform -- deliberately, see the test above. This is the
     * exception the manifest argues for: that stack is a contributor
     * environment whose nginx points at a src/ with no wp-config.php in it, so
     * deploying it answers 500 on every request.
     */
    public function test_the_wordpress_develop_checkout_beats_its_own_compose_file(): void
    {
        $this->write('docker-compose.yml', "services:\n  wordpress-develop:\n    image: nginx:alpine\n");
        $this->writeJson('package.json', ['scripts' => ['build' => 'grunt build', 'env:install' => 'wp core install']]);
        $this->write('Gruntfile.js', 'module.exports = function (grunt) {};');
        $this->write('src/wp-settings.php', '<?php // boot');
        $this->write('src/wp-includes/version.php', '<?php $wp_version = "6.9-alpha";');

        $this->assertSame('wordpress-develop', $this->selected());
    }

    /** The two WordPress manifests must not claim each other's layout. */
    public function test_a_released_wordpress_is_not_taken_for_the_develop_checkout(): void
    {
        $this->write('wp-settings.php', '<?php // boot');
        $this->write('wp-login.php', '<?php // login');
        $this->write('wp-includes/version.php', '<?php $wp_version = "6.7";');
        $this->write('wp-admin/index.php', '<?php // dashboard');
        // A released WordPress has no build tooling at its root.
        $this->assertFileDoesNotExist($this->dir . '/Gruntfile.js');

        $this->assertSame('wordpress', $this->selected());
    }

    /**
     * A theme or a plugin lives inside wp-content and vendors none of this;
     * the two markers together are what keep them out.
     */
    public function test_a_directory_that_merely_mentions_wordpress_is_not_claimed(): void
    {
        $this->write('style.css', '/* Theme Name: Acme */');
        $this->write('functions.php', '<?php // theme');

        $this->assertNotSame('wordpress', $this->selected());
    }
}
