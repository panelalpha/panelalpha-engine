<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Php\PhpDocroot;
use PHPUnit\Framework\TestCase;

/**
 * A PHP manifest declares where its document root is, not how to serve it.
 *
 * Every shipped PHP manifest used to carry its own copy of
 * `{ PA_DOCROOT=/app/…; export PA_DOCROOT; exec apache2-foreground; }` --
 * fourteen statements of the same fact, differing only in the directory.
 */
class PhpDocrootTest extends TestCase
{
    private function manifest(string $docroot): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => 'sample',
            'label' => 'Sample',
            'priority' => 1,
            'runtime' => 'php',
            'docroot' => $docroot,
            'detect' => ['all' => [['file' => 'composer.json']]],
        ]);
    }

    public function test_the_declared_docroot_reaches_the_decision(): void
    {
        $decision = $this->manifest('public')->describe(ProjectContext::make('/tmp', []));

        $this->assertSame('public', $decision['docroot']);
    }

    /**
     * '.' and an omitted key are the same statement: the application root.
     * Both leave the image to decide, which it does by looking for public/.
     */
    public function test_a_root_docroot_normalises_to_empty(): void
    {
        $this->assertSame('', $this->manifest('.')->docroot);
        $this->assertSame('', $this->manifest('/')->docroot);
    }

    /**
     * The value becomes Apache's DocumentRoot. '../' there would serve the
     * account's home directory.
     */
    public function test_a_docroot_that_escapes_the_application_is_refused(): void
    {
        foreach (['../etc', 'a/../../b', 'a//b'] as $hostile) {
            try {
                $this->manifest($hostile);
                $this->fail("docroot '{$hostile}' was accepted");
            } catch (ManifestException $e) {
                $this->assertStringContainsString('docroot', $e->getMessage());
            }
        }
    }

    public function test_the_shipped_manifests_declare_the_roots_they_used_to_hardcode(): void
    {
        $expected = [
            'laravel' => 'public',
            'flarum' => 'public',
            'magento' => 'pub',
            'opencart' => 'upload',
            'matomo' => '',
            'adminer' => '',
            // php, phpmyadmin and chamilo used the `if [ -d /app/public ]`
            // form, which is exactly what an undeclared docroot means.
            'php' => '',
            'phpmyadmin' => '',
            'chamilo' => '',
        ];

        foreach ($expected as $id => $docroot) {
            $manifest = PlatformRegistry::find($id);
            $this->assertNotNull($manifest, $id);
            $this->assertSame($docroot, $manifest->docroot, $id);
        }
    }

    /**
     * The serve command is supplied for the PHP runtime, and it has to be a
     * command rather than nothing: EntrypointWriter writes no entrypoint at
     * all for a manifest without one, and a Laravel account with no
     * entrypoint gets no key:generate and no migrate.
     */
    public function test_the_php_runtime_is_given_a_serve_command(): void
    {
        $serve = $this->manifest('public')->serveCommand();

        $this->assertNotNull($serve);
        $this->assertTrue($serve->serve);
        $this->assertSame(PhpBaseImage::SERVE_PATH, $serve->run);
    }

    /**
     * A manifest that does declare its own serve keeps it — an application
     * whose server is not Apache at all is still expressible.
     */
    public function test_a_declared_serve_command_still_wins(): void
    {
        $manifest = PlatformManifest::fromArray([
            'id' => 'sample',
            'label' => 'Sample',
            'priority' => 1,
            'runtime' => 'php',
            'detect' => ['all' => [['file' => 'composer.json']]],
            'commands' => [[
                'id' => 'serve',
                'stage' => 'start',
                'serve' => true,
                'run' => 'exec php -S 0.0.0.0:8000',
            ]],
        ]);

        $this->assertSame('exec php -S 0.0.0.0:8000', $manifest->serveCommand()->run);
    }

    /** @param list<string> $files */
    private static function tree(array $files): \Closure
    {
        return fn (string $relative): bool => in_array($relative, $files, true);
    }

    /** Dotclear 2.39: root index.php, admin/ beside it, an empty public/. */
    public function test_an_empty_public_directory_does_not_win_over_a_root_index(): void
    {
        $dotclear = self::tree(['index.php', 'admin/index.php', 'inc/prepend.php']);

        $this->assertSame(PhpDocroot::ROOT, PhpDocroot::detect($dotclear));
        $this->assertSame(['PA_DOCROOT' => '/app'], PhpDocroot::environment('', $dotclear));
    }

    public function test_a_public_index_picks_public(): void
    {
        $this->assertSame(
            ['PA_DOCROOT' => '/app/public'],
            PhpDocroot::environment(null, self::tree(['index.php', 'public/index.php']))
        );
        $this->assertSame('public', PhpDocroot::detect(self::tree(['public/index.html'])));
        $this->assertSame('web', PhpDocroot::detect(self::tree(['index.php', 'web/index.php'])));
    }

    /**
     * Phorge keeps its entry point in webroot/ and WackoWiki in src/. Neither
     * was a candidate, so the document root stayed at the project root, Apache
     * had no index to serve, and both answered 403.
     */
    public function test_webroot_and_src_are_recognised_docroots(): void
    {
        $this->assertSame(
            ['PA_DOCROOT' => '/app/webroot'],
            PhpDocroot::environment(null, self::tree(['webroot/index.php', 'src/App.php']))
        );
        $this->assertSame(
            ['PA_DOCROOT' => '/app/src'],
            PhpDocroot::environment(null, self::tree(['src/index.php']))
        );
        // A candidate outranks the root, exactly as public/ already did: a
        // project with both is served from the subdirectory.
        $this->assertSame('webroot', PhpDocroot::detect(self::tree(['index.php', 'webroot/index.php'])));
    }

    /** A declared root is never second-guessed, even by a tree that would say otherwise. */
    public function test_the_laravel_manifest_keeps_its_declared_public(): void
    {
        $looked = false;
        $env = PhpDocroot::environment(
            PlatformRegistry::find('laravel')->docroot,
            function () use (&$looked): bool {
                $looked = true;

                return false;
            }
        );

        $this->assertSame(['PA_DOCROOT' => '/app/public'], $env);
        $this->assertFalse($looked);
    }

    /** Symfony declares nothing, and its public/index.php still decides it. */
    public function test_symfony_still_gets_public(): void
    {
        $symfony = self::tree(['composer.json', 'public/index.php', 'bin/console']);

        $this->assertSame('', PlatformRegistry::find('php')->docroot);
        $this->assertSame(['PA_DOCROOT' => '/app/public'], PhpDocroot::environment('', $symfony));
    }

    /**
     * Seven apps in the 2026-09 support batch answered 403 for one reason: the
     * document root was a directory nothing probed, so Apache was handed the
     * project root and had no index to serve.
     */
    public function test_the_remaining_conventional_docroot_names_are_recognised(): void
    {
        $cases = [
            'www' => 'zentao, Group Office, Bluecherry',
            'htdocs' => 'DAViCal',
            'source' => 'OXID eShop',
            'upload' => 'ClipBucket',
            'webui' => 'piler',
        ];

        foreach ($cases as $dir => $apps) {
            $this->assertSame(
                ['PA_DOCROOT' => '/app/' . $dir],
                PhpDocroot::environment(null, self::tree(['composer.json', $dir . '/index.php'])),
                "{$dir}/ is the document root of {$apps}"
            );
        }
    }

    /**
     * The whole reason these are late-ranked. A project with its own front
     * controller keeps it -- otherwise adding names would move the document
     * root of applications that serve correctly today.
     */
    public function test_a_root_front_controller_still_outranks_a_late_candidate(): void
    {
        foreach (['www', 'htdocs', 'source', 'upload', 'webui', 'src'] as $dir) {
            $this->assertSame(
                PhpDocroot::ROOT,
                PhpDocroot::detect(self::tree(['index.php', $dir . '/index.php'])),
                "a root index.php must outrank {$dir}/"
            );
        }
    }

    /** And a reserved candidate outranks all of them, wherever the root has none. */
    public function test_public_still_outranks_a_late_candidate(): void
    {
        $this->assertSame(
            'public',
            PhpDocroot::detect(self::tree(['public/index.php', 'www/index.php']))
        );
    }

    /**
     * `src/` is last on purpose: it is the one name here that usually holds
     * *source*, so any other match must win over it.
     */
    public function test_src_is_the_last_resort(): void
    {
        $this->assertSame(
            'www',
            PhpDocroot::detect(self::tree(['www/index.php', 'src/index.php']))
        );
        $this->assertSame('src', PhpDocroot::detect(self::tree(['src/index.php'])));
    }

    /**
     * Sympa owns www/ and AWStats owns wwwroot/, and both are Perl programs
     * with no PHP entry point in them. The index.php requirement is what keeps
     * a directory that merely has a matching *name* from becoming a docroot.
     */
    public function test_a_matching_directory_without_an_index_is_not_a_docroot(): void
    {
        $this->assertSame('', PhpDocroot::detect(self::tree(['www/index.pl', 'www/style.css'])));
        $this->assertSame('', PhpDocroot::detect(self::tree(['www/index.html'])));
    }

    /** Nothing to go on: no PA_DOCROOT, so the image's own public/-or-root fallback applies. */
    public function test_no_index_anywhere_leaves_it_to_the_image(): void
    {
        $this->assertSame('', PhpDocroot::detect(self::tree(['composer.json', 'src/App.php'])));
        $this->assertSame([], PhpDocroot::environment('', self::tree([])));
        $this->assertSame('', PhpDocroot::detect(self::tree(['public/index.htm'])));
    }
}
