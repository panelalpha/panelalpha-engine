<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Template\TemplateLoader;
use PHPUnit\Framework\TestCase;

/**
 * What the container publishes when nothing declared a document root.
 *
 * `PhpDocroot::detect()` returns '' for a repository with no index file in any
 * candidate directory, `environment()` then emits no PA_DOCROOT, and the serve
 * script decided. It used to answer `/app` -- the whole checkout, with
 * Apache's PHP handler active over it. Measured on inovector/mixpost, a
 * Laravel *package* with no index.php anywhere: `composer.lock` came back 200
 * at 418,679 bytes, and every .php under src/, config/, routes/ and vendor/
 * was directly invocable.
 *
 * The distinction the script now makes is the one that was missing: an app
 * with a front controller in an unexpected place is still worth guessing at,
 * and a checkout with no front controller at all has nothing to serve, so
 * serving the repository cannot be helping.
 */
class PhpServeFallbackTest extends TestCase
{
    private function script(): string
    {
        return TemplateLoader::asset(PhpBaseImage::SERVE_ASSET);
    }

    /**
     * The script hardcodes the path -- it is shell, and it runs where the
     * catalogue does not. This is what keeps the two spellings one value.
     */
    public function test_the_script_and_the_image_agree_on_the_empty_root(): void
    {
        $this->assertStringContainsString(
            'EMPTY_DOCROOT=' . PhpBaseImage::EMPTY_DOCROOT,
            $this->script()
        );
    }

    /** An empty directory has to be created, or Apache refuses to boot. */
    public function test_the_image_creates_it(): void
    {
        $this->assertStringContainsString(
            'mkdir -p ' . PhpBaseImage::EMPTY_DOCROOT,
            (string) PhpBaseImage::dockerfile('php:8.3-apache-bookworm')
        );
    }

    /** Outside /app, which is the customer's bind mount. */
    public function test_the_empty_root_is_not_reachable_over_sftp(): void
    {
        $this->assertStringStartsNotWith('/app', PhpBaseImage::EMPTY_DOCROOT);
    }

    /** public/ still wins, and a root front controller is still served. */
    public function test_the_ordinary_layouts_are_unchanged(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('PA_DOCROOT=/app/public', $script);
        $this->assertStringContainsString('PA_DOCROOT=/app', $script);
    }

    /** Both fallbacks -- the undeclared one and the missing-directory one. */
    public function test_neither_fallback_publishes_a_tree_with_no_entry_point(): void
    {
        $script = $this->script();

        $this->assertSame(
            2,
            substr_count($script, 'PA_DOCROOT=$EMPTY_DOCROOT'),
            'a declared docroot that does not exist falls back the same way'
        );
        $this->assertStringContainsString('has_index()', $script);
    }

    public function test_it_is_still_valid_shell(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pa-serve');
        $this->assertIsString($file);
        file_put_contents($file, $this->script());
        exec('sh -n ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        @unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }
}
