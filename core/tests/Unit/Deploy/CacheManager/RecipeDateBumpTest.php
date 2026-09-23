<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\Php\PhpApacheConfig;
use App\Lib\Deploy\Platform\Runtime\Php\PhpIniDefaults;
use App\Lib\Deploy\Template\TemplateLoader;
use PHPUnit\Framework\TestCase;

/**
 * The baked extension list and the recipe date have to move together.
 *
 * The image tag is the catalogue's `recipe` date, not a hash of what the
 * image contains — `PhpBaseImage::fingerprint()` returns
 * `RuntimeImageCatalog::recipeDate('php')`. The comment beside it says the
 * consequence out loud: change the extension list without bumping the date
 * and every host already holding the old image keeps serving it under a name
 * that now promises something else. Nothing detected that.
 *
 * This does. It fails whenever EXTENSIONS changes until the date is bumped
 * too, which turns a silent stale image into a failing test.
 *
 * **When this test fails:** bump `recipe` in `config/core/images.yaml`,
 * then update the fingerprint below to the value the failure prints. Both, in
 * the same edit. If the date is already today's, either move to the next day
 * or drop the built images from every host that holds them, so this date
 * rebuilds them — the format is day-granular and cannot express two changes
 * in one day on its own.
 */
class RecipeDateBumpTest extends TestCase
{
    /**
     * sha1 of the baked extension list, as of the recipe date below.
     */
    private const EXTENSIONS_FINGERPRINT = '989f11d9c144bca048cbe76b0430bc9317779289';

    private const RECIPE_DATE        = '20260922';

    public function test_the_recipe_date_was_bumped_with_the_extension_list(): void
    {
        $actual = sha1(implode(' ', PhpBaseImage::EXTENSIONS));

        if ($actual !== self::EXTENSIONS_FINGERPRINT) {
            $this->assertNotSame(
                self::RECIPE_DATE,
                PhpBaseImage::fingerprint(),
                "PhpBaseImage::EXTENSIONS changed but the catalogue's `recipe` date did not.\n"
                . "Every host holding the old image would keep serving it under the same tag.\n"
                . "Bump `recipe` in config/core/images.yaml, then set\n"
                . "  EXTENSIONS_FINGERPRINT = '{$actual}'\n"
                . "  RECIPE_DATE            = '" . PhpBaseImage::fingerprint() . "'\n"
                . 'in this test.'
            );
        }

        $this->assertSame(
            self::EXTENSIONS_FINGERPRINT,
            $actual,
            "The extension list changed. Bump `recipe` in config/core/images.yaml,\n"
            . "then set EXTENSIONS_FINGERPRINT = '{$actual}' here."
        );
    }

    /**
     * sha1 of the Apache configuration baked into the same image, as of the
     * same recipe date.
     */
    private const APACHE_FINGERPRINT = '19c4ee6b8767d051355bba7029ad9f560f3c7715';

    /**
     * The extension list is not the only thing baked into that image.
     *
     * {@see PhpBaseImage::dockerfile()} writes the vhost, the ports file and
     * the module list into it as well, and they are every bit as invisible: a
     * host holding the old image keeps serving the old Apache configuration
     * under a tag that now promises the new one. Koel is what made the gap
     * concrete -- the fix it suggests, teaching the vhost to route a Laravel
     * app that ships no `public/.htaccess` of its own, is a change to a file
     * that lives inside the image and is guarded by nothing.
     *
     * Same rule and same remedy as the extension list above.
     */
    public function test_the_recipe_date_was_bumped_with_the_apache_config(): void
    {
        $actual = sha1(
            PhpApacheConfig::vhost(8000) . "\n"
            . PhpApacheConfig::ports(8000) . "\n"
            . PhpApacheConfig::modules()
        );

        if ($actual !== self::APACHE_FINGERPRINT) {
            $this->assertNotSame(
                self::RECIPE_DATE,
                PhpBaseImage::fingerprint(),
                "The baked Apache configuration changed but the catalogue's `recipe` date did not.\n"
                . "Every host holding the old image would keep serving the old vhost.\n"
                . "Bump `recipe` in config/core/images.yaml, then set\n"
                . "  APACHE_FINGERPRINT = '{$actual}'\n"
                . "  RECIPE_DATE        = '" . PhpBaseImage::fingerprint() . "'\n"
                . 'in this test.'
            );
        }

        $this->assertSame(
            self::APACHE_FINGERPRINT,
            $actual,
            "The Apache configuration changed. Bump `recipe` in config/core/images.yaml,\n"
            . "then set APACHE_FINGERPRINT = '{$actual}' here."
        );
    }

    /**
     * sha1 of the php.ini defaults baked into the same image, as of the same
     * recipe date.
     */
    private const PHP_INI_FINGERPRINT = '2502c2b32a40c7cc845f54dbf1389c039d0dca9b';

    /**
     * The third invisible file in that image.
     *
     * The official php image loads no php.ini, so
     * {@see PhpIniDefaults} is the only thing standing between a deployed app
     * and `display_errors=1` -- notices written into the response body, which
     * sends the headers and takes session_start() with them. A host holding
     * the old image keeps serving without it under a tag that promises
     * otherwise.
     *
     * Same rule and same remedy as the two above.
     */
    public function test_the_recipe_date_was_bumped_with_the_php_ini_defaults(): void
    {
        $actual = sha1(PhpIniDefaults::INI_PATH . "\n" . PhpIniDefaults::ini());

        if ($actual !== self::PHP_INI_FINGERPRINT) {
            $this->assertNotSame(
                self::RECIPE_DATE,
                PhpBaseImage::fingerprint(),
                "The baked php.ini defaults changed but the catalogue's `recipe` date did not.\n"
                . "Every host holding the old image would keep serving the old directives.\n"
                . "Bump `recipe` in config/core/images.yaml, then set\n"
                . "  PHP_INI_FINGERPRINT = '{$actual}'\n"
                . "  RECIPE_DATE         = '" . PhpBaseImage::fingerprint() . "'\n"
                . 'in this test.'
            );
        }

        $this->assertSame(
            self::PHP_INI_FINGERPRINT,
            $actual,
            "The php.ini defaults changed. Bump `recipe` in config/core/images.yaml,\n"
            . "then set PHP_INI_FINGERPRINT = '{$actual}' here."
        );
    }

    /**
     * sha1 of the serve script and the directory it falls back to, as of the
     * same recipe date.
     */
    private const SERVE_FINGERPRINT = '0caf58f76b449562b2a79030253eec5a57a605d3';

    /**
     * The fourth, and the one with the widest blast radius.
     *
     * {@see PhpBaseImage::SERVE_ASSET} decides what a container publishes when
     * nothing declared a document root. It used to answer `/app` for a
     * checkout with no front controller at all, which served the whole source
     * tree with the PHP handler active over it. A host holding the old image
     * keeps doing that under a tag that promises otherwise.
     *
     * Same rule and same remedy as the three above.
     */
    public function test_the_recipe_date_was_bumped_with_the_serve_script(): void
    {
        $actual = sha1(
            PhpBaseImage::EMPTY_DOCROOT . "\n"
            . rtrim(TemplateLoader::asset(PhpBaseImage::SERVE_ASSET))
        );

        if ($actual !== self::SERVE_FINGERPRINT) {
            $this->assertNotSame(
                self::RECIPE_DATE,
                PhpBaseImage::fingerprint(),
                "The baked serve script changed but the catalogue's `recipe` date did not.\n"
                . "Every host holding the old image would keep serving the old document root.\n"
                . "Bump `recipe` in config/core/images.yaml, then set\n"
                . "  SERVE_FINGERPRINT = '{$actual}'\n"
                . "  RECIPE_DATE       = '" . PhpBaseImage::fingerprint() . "'\n"
                . 'in this test.'
            );
        }

        $this->assertSame(
            self::SERVE_FINGERPRINT,
            $actual,
            "The serve script changed. Bump `recipe` in config/core/images.yaml,\n"
            . "then set SERVE_FINGERPRINT = '{$actual}' here."
        );
    }

    /** The extensions two applications in the supported-apps series died for. */
    public function test_the_extensions_real_applications_required_are_baked(): void
    {
        // ownCloud: "Root composer.json requires PHP extension ext-memcached".
        $this->assertContains('memcached', PhpBaseImage::EXTENSIONS);
        // Passbolt: "Root composer.json requires PHP extension ext-gnupg".
        $this->assertContains('gnupg', PhpBaseImage::EXTENSIONS);
    }

    /** A date the catalogue does not declare would mean an untagged image. */
    public function test_the_recipe_date_is_declared(): void
    {
        $this->assertMatchesRegularExpression('/^\d{8}$/', (string) PhpBaseImage::fingerprint());
    }
}
